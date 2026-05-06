<?php

namespace App\Console\Commands\Shopify;

use App\Models\EWeb\RetailEdgeProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\GraphQL\ProductMutations;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use App\Traits\ShopifyCleanupTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Variant-level duplicate cleanup.
 *
 * Detects child SKUs (retail_edge_products.old_key != sku) that have been
 * created as variants on more than one Shopify product, keeps the variant
 * sitting on the correct parent product, and deletes the rest. Standalone
 * and parent SKUs (old_key = sku, or empty) are skipped — those are
 * handled by shopify:delete-duplicate-products.
 */
class DeleteDuplicateVariants extends Command
{
    use ShopifyCleanupTrait;

    protected $signature = 'shopify:delete-duplicate-variants
        {--dry-run : Preview what would be deleted without making changes}
        {--force : Skip confirmation prompt}
        {--sku= : Target a specific SKU (optional)}';

    protected $description = 'Delete duplicate Shopify variants caused by child SKUs being created as standalone products.';

    private ShopifyGraphQLService $graphqlService;

    private array $stats = [
        'duplicates_found' => 0,
        'skipped_standalone' => 0,
        'kept_on_correct_product' => 0,
        'products_deleted' => 0,
        'variants_deleted' => 0,
        'deleted_database' => 0,
        'errors' => 0,
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $targetSku = $this->option('sku');

        $job = (new SyncJobService)->claim('delete-duplicate-variants', 'Shopify');

        if (! $job) {
            $this->info('shopify:delete-duplicate-variants is already running.');
            Log::info('shopify:delete-duplicate-variants skipped: job already running');

            return self::SUCCESS;
        }

        try {
            $this->info('Shopify Duplicate Variants Cleanup');
            $this->info('==================================');

            if ($isDryRun) {
                $this->warn('DRY RUN MODE - no changes will be made');
            }

            if ($targetSku) {
                $this->info("Targeting specific SKU: {$targetSku}");
            }

            if (! $isDryRun) {
                $this->graphqlService = new ShopifyGraphQLService;
            }

            $this->newLine();
            $this->info('Step 1: Finding duplicate SKUs…');
            $duplicateSkus = $this->findDuplicateSkus($targetSku);

            if (empty($duplicateSkus)) {
                $this->info('No duplicate variants found.');
                $job->update(['status' => 0, 'message' => null]);

                return self::SUCCESS;
            }

            $this->stats['duplicates_found'] = count($duplicateSkus);
            $extra = array_sum(array_map(fn ($d) => $d->count - 1, $duplicateSkus));
            $this->info("Found {$this->stats['duplicates_found']} SKUs with duplicates ({$extra} extra variants)");

            if (! $isDryRun && ! $this->option('force')) {
                if (! $this->confirm("Process {$this->stats['duplicates_found']} duplicate SKUs?")) {
                    $this->info('Operation cancelled.');
                    $job->update(['status' => 0, 'message' => null]);

                    return self::SUCCESS;
                }
            }

            $this->newLine();
            $this->info('Step 2: Processing duplicates…');

            $progressBar = $this->output->createProgressBar(count($duplicateSkus));
            $progressBar->start();

            foreach ($duplicateSkus as $duplicate) {
                $this->processDuplicateSku($duplicate->sku, $isDryRun);
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();

            $this->displaySummary($isDryRun);

            $job->update(['status' => 0, 'message' => null]);

            return $this->stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function findDuplicateSkus(?string $targetSku): array
    {
        $query = DB::table('shopify_product_variants')
            ->select('sku', DB::raw('COUNT(*) as count'))
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->groupBy('sku')
            ->having('count', '>', 1);

        if ($targetSku) {
            $query->where('sku', $targetSku);
        }

        return $query->get()->toArray();
    }

    /**
     * @return array{is_child: bool, parent_sku: string|null}
     */
    private function isChildProduct(string $sku): array
    {
        $retailEdge = RetailEdgeProduct::where('sku', $sku)->first();

        if (! $retailEdge || empty($retailEdge->old_key)) {
            return ['is_child' => false, 'parent_sku' => null];
        }

        $isChild = $retailEdge->old_key !== $retailEdge->sku;

        return [
            'is_child' => $isChild,
            'parent_sku' => $isChild ? $retailEdge->old_key : null,
        ];
    }

    private function getCorrectProductId(string $parentSku): ?int
    {
        $parentProductIds = ShopifyProductVariant::where('sku', $parentSku)
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->unique()
            ->values();

        if ($parentProductIds->isEmpty()) {
            Log::warning('DeleteDuplicateVariants: parent SKU not found in Shopify mirror', [
                'parent_sku' => $parentSku,
            ]);

            return null;
        }

        if ($parentProductIds->count() > 1) {
            // Parent itself is duplicated upstream. shopify:delete-duplicate-products
            // should run first; until it does, we pick the lowest product_id
            // for stability so re-runs land on the same target.
            Log::warning('DeleteDuplicateVariants: parent SKU is on multiple Shopify products; pick may be unstable', [
                'parent_sku' => $parentSku,
                'parent_product_ids' => $parentProductIds->all(),
            ]);
        }

        return (int) $parentProductIds->min();
    }

    private function processDuplicateSku(string $sku, bool $isDryRun): void
    {
        $this->newLine();
        $this->line("  Processing SKU: {$sku}");

        $childCheck = $this->isChildProduct($sku);

        if (! $childCheck['is_child']) {
            $this->info('    SKIP: not a child product (standalone/parent or unknown SKU)');
            $this->stats['skipped_standalone']++;

            return;
        }

        $parentSku = $childCheck['parent_sku'];
        $this->line("    Parent SKU: {$parentSku}");

        $correctProductId = $this->getCorrectProductId($parentSku);
        $variants = ShopifyProductVariant::where('sku', $sku)
            ->orderBy('variant_id', 'desc')
            ->get();

        $this->line("    Variants found: {$variants->count()}");
        $this->line('    Correct product_id: '.($correctProductId ?? 'NOT FOUND (parent missing on Shopify)'));

        foreach ($variants as $variant) {
            if ($correctProductId && (int) $variant->product_id === $correctProductId) {
                $this->info("    KEEP variant_id={$variant->variant_id} (on correct parent)");
                $this->stats['kept_on_correct_product']++;

                continue;
            }

            // A row missing either ID can't be addressed against Shopify.
            // Force-clean it locally and move on; the next sync will
            // reconcile with whatever exists upstream.
            if (empty($variant->product_id) || empty($variant->variant_id)) {
                $this->warn("    SKIP variant with missing IDs (sku={$variant->sku}); cleaning local row only.");
                Log::warning('DeleteDuplicateVariants: variant missing product_id/variant_id, cleaning locally only', [
                    'sku' => $variant->sku,
                    'shopify_product_id' => $variant->product_id,
                    'shopify_variant_id' => $variant->variant_id,
                ]);
                if (! $isDryRun) {
                    $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
                    $this->stats['deleted_database']++;
                }

                continue;
            }

            $this->line("    DELETE variant_id={$variant->variant_id} (on wrong product {$variant->product_id})");

            $productVariantCount = ShopifyProductVariant::where('product_id', $variant->product_id)->count();

            if ($isDryRun) {
                $this->line($productVariantCount <= 1
                    ? "      [DRY RUN] would delete entire product {$variant->product_id}"
                    : '      [DRY RUN] would delete variant from product');

                continue;
            }

            try {
                if ($productVariantCount <= 1) {
                    $this->deleteEntireProduct($variant, $sku, $parentSku);
                } else {
                    $this->deleteOneVariant($variant, $sku, $parentSku);
                }
            } catch (\Throwable $e) {
                $this->handleDeletionError($e, $variant, $sku, $parentSku);
            }

            usleep(100_000);
        }
    }

    /**
     * Delete the entire Shopify product and cascade the local mirror.
     */
    private function deleteEntireProduct(ShopifyProductVariant $variant, string $sku, ?string $parentSku): void
    {
        $this->line('      Deleting entire product (only variant)…');
        $this->deleteProductFromShopify((int) $variant->product_id);

        $this->cleanupVariantsForProduct((int) $variant->product_id);
        $this->stats['products_deleted']++;
        $this->stats['deleted_database']++;
        $this->info('      Deleted product successfully');

        Log::info('DeleteDuplicateVariants: deleted duplicate product (only variant on wrong product)', [
            'sku' => $sku,
            'parent_sku' => $parentSku,
            'shopify_product_id' => $variant->product_id,
            'shopify_variant_id' => $variant->variant_id,
        ]);
    }

    /**
     * Delete a single variant from a Shopify product. If Shopify rejects
     * the delete because it's actually the last variant (mirror said
     * otherwise), fall back to deleting the whole product. This handles
     * the case where the local count drifted from upstream reality.
     */
    private function deleteOneVariant(ShopifyProductVariant $variant, string $sku, ?string $parentSku): void
    {
        $this->line('      Deleting variant from product…');

        try {
            $this->deleteVariantFromShopify((int) $variant->product_id, (int) $variant->variant_id);
        } catch (\Throwable $e) {
            if ($this->isLastVariantError($e->getMessage())) {
                $this->warn('      Shopify reports this is the only variant; falling back to product delete.');
                Log::info('DeleteDuplicateVariants: bulkDelete refused (last variant), falling back to productDelete', [
                    'sku' => $sku,
                    'shopify_product_id' => $variant->product_id,
                    'shopify_variant_id' => $variant->variant_id,
                ]);

                $this->deleteEntireProduct($variant, $sku, $parentSku);

                return;
            }

            throw $e;
        }

        $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
        $this->stats['variants_deleted']++;
        $this->stats['deleted_database']++;
        $this->info('      Deleted variant successfully');

        Log::info('DeleteDuplicateVariants: deleted duplicate variant from wrong product', [
            'sku' => $sku,
            'parent_sku' => $parentSku,
            'shopify_product_id' => $variant->product_id,
            'shopify_variant_id' => $variant->variant_id,
        ]);
    }

    private function isLastVariantError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'last variant')
            || str_contains($lower, 'only variant')
            || str_contains($lower, 'cannot delete the only')
            || str_contains($lower, 'must have at least one variant');
    }

    private function handleDeletionError(\Throwable $e, ShopifyProductVariant $variant, string $sku, ?string $parentSku): void
    {
        $message = $e->getMessage();

        if ($this->isResourceNotFoundError($message)) {
            $this->warn('      Not found on Shopify — cleaning local mirror only');
            $this->cleanupStaleVariant($variant, 'DeleteDuplicateVariants');
            $this->stats['deleted_database']++;

            Log::info('DeleteDuplicateVariants: cleaned stale local mirror (not on Shopify)', [
                'sku' => $sku,
                'parent_sku' => $parentSku,
                'shopify_product_id' => $variant->product_id,
                'shopify_variant_id' => $variant->variant_id,
            ]);

            return;
        }

        $this->error("      Failed: {$message}");
        $this->stats['errors']++;

        Log::error('DeleteDuplicateVariants: deletion failed', [
            'sku' => $sku,
            'parent_sku' => $parentSku,
            'shopify_product_id' => $variant->product_id,
            'shopify_variant_id' => $variant->variant_id,
            'error' => $message,
        ]);
    }

    private function cleanupVariantsForProduct(int $productId): void
    {
        $variants = ShopifyProductVariant::where('product_id', $productId)->get();
        foreach ($variants as $v) {
            $this->cleanupStaleVariant($v, 'DeleteDuplicateVariants');
        }
    }

    private function deleteVariantFromShopify(int $productId, int $variantId): void
    {
        $response = $this->graphqlService->mutate(
            ProductMutations::productVariantsBulkDelete(),
            [
                'productId' => $this->graphqlService->formatGraphQLId('Product', $productId),
                'variantsIds' => [$this->graphqlService->formatGraphQLId('ProductVariant', $variantId)],
            ]
        );

        $userErrors = $response['productVariantsBulkDelete']['userErrors'] ?? [];
        if (! empty($userErrors)) {
            throw new \RuntimeException($this->formatUserErrors($userErrors));
        }
    }

    private function deleteProductFromShopify(int $productId): void
    {
        $response = $this->graphqlService->mutate(
            ProductMutations::deleteProduct(),
            ['input' => ['id' => $this->graphqlService->formatGraphQLId('Product', $productId)]]
        );

        $userErrors = $response['productDelete']['userErrors'] ?? [];
        if (! empty($userErrors)) {
            throw new \RuntimeException($this->formatUserErrors($userErrors));
        }
    }

    private function formatUserErrors(array $userErrors): string
    {
        return implode(', ', array_map(
            fn ($e) => ($e['field'][0] ?? 'error').': '.($e['message'] ?? 'unknown'),
            $userErrors
        ));
    }

    private function isResourceNotFoundError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'does not exist')
            || str_contains($lower, 'not found')
            || str_contains($lower, 'no such')
            || str_contains($lower, 'invalid global id');
    }

    private function displaySummary(bool $isDryRun): void
    {
        $this->newLine();
        $this->info('Summary:');
        $this->info('========');
        $this->info("  Duplicate SKUs processed: {$this->stats['duplicates_found']}");
        $this->info("  Skipped (standalone/parent): {$this->stats['skipped_standalone']}");
        $this->info("  Kept on correct parent: {$this->stats['kept_on_correct_product']}");

        if ($isDryRun) {
            $this->warn('  [DRY RUN] no changes were made');
        } else {
            $this->info("  Products deleted from Shopify: {$this->stats['products_deleted']}");
            $this->info("  Variants deleted from Shopify: {$this->stats['variants_deleted']}");
            $this->info("  Local DB records cleaned: {$this->stats['deleted_database']}");
            $this->info("  Errors: {$this->stats['errors']}");
        }
    }
}
