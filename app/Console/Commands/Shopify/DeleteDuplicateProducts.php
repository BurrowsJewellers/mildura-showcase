<?php

namespace App\Console\Commands\Shopify;

use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\GraphQL\ProductMutations;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use App\Traits\ShopifyCleanupTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parent-level duplicate-product cleanup.
 *
 * Detects parent/standalone SKUs (retail_edge_products.old_key = sku, or
 * empty) that appear as a variant on more than one live Shopify product,
 * picks the most-complete copy to keep (most legitimate child variants,
 * tiebreak on oldest created_at then lowest product_id), and hard-deletes
 * the rest from Shopify with a cascading hard-delete of the local mirror.
 *
 * Companion to shopify:delete-duplicate-variants which handles the
 * disjoint case of child SKUs duplicated across products. Run this one
 * first so the variant pass has a single, stable parent product to
 * compare against.
 */
class DeleteDuplicateProducts extends Command
{
    use ShopifyCleanupTrait;

    protected $signature = 'shopify:delete-duplicate-products
        {--dry-run : Preview what would be deleted without making changes}
        {--force : Skip confirmation prompt}
        {--sku= : Target a specific parent SKU (optional)}
        {--limit= : Cap the number of duplicate parent SKUs to process}';

    protected $description = 'Delete duplicate Shopify products that share a parent SKU. Keeps the most-complete copy, tiebreaks on oldest then lowest product_id.';

    private ShopifyGraphQLService $graphqlService;

    private array $stats = [
        'duplicate_parents_found' => 0,
        'kept' => 0,
        'skipped' => 0,
        'products_deleted' => 0,
        'variants_cascaded' => 0,
        'errors' => 0,
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $targetSku = $this->option('sku');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $job = (new SyncJobService)->claim('delete-duplicate-products', 'Shopify');

        if (! $job) {
            $this->info('shopify:delete-duplicate-products is already running.');
            Log::info('shopify:delete-duplicate-products skipped: job already running');

            return self::SUCCESS;
        }

        try {
            $this->info('Shopify Duplicate Products Cleanup (parent-level)');
            $this->info('=================================================');

            if ($isDryRun) {
                $this->warn('DRY RUN MODE - no changes will be made');
            }

            if ($targetSku) {
                $this->info("Targeting specific SKU: {$targetSku}");
            }

            if ($limit !== null) {
                $this->info("Limit: {$limit}");
            }

            if (! $isDryRun) {
                $this->graphqlService = new ShopifyGraphQLService;
            }

            $this->newLine();
            $this->info('Step 1: Finding parent SKUs on multiple live Shopify products…');
            $duplicates = $this->findDuplicateParents($targetSku, $limit);

            if (empty($duplicates)) {
                $this->info('No duplicate parent products found.');
                $job->update(['status' => 0, 'message' => null]);

                return self::SUCCESS;
            }

            $this->stats['duplicate_parents_found'] = count($duplicates);
            $totalToDelete = array_sum(array_map(fn ($d) => $d->instances - 1, $duplicates));
            $this->info("Found {$this->stats['duplicate_parents_found']} parent SKUs on multiple live products ({$totalToDelete} extra products to delete)");

            if (! $isDryRun && ! $this->option('force')) {
                if (! $this->confirm("Delete approximately {$totalToDelete} duplicate Shopify products?")) {
                    $this->info('Operation cancelled.');
                    $job->update(['status' => 0, 'message' => null]);

                    return self::SUCCESS;
                }
            }

            $this->newLine();
            $this->info('Step 2: Processing duplicates…');

            $progressBar = $this->output->createProgressBar(count($duplicates));
            $progressBar->start();

            foreach ($duplicates as $duplicate) {
                $this->processParentSku($duplicate->sku, $isDryRun);
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

    /**
     * Find parent SKUs (old_key = sku, or empty) that have >1 live Shopify
     * product. ShopifyProduct uses SoftDeletes — `whereNull('sp.deleted_at')`
     * keeps soft-deleted rows out of the duplicate-count.
     */
    private function findDuplicateParents(?string $targetSku, ?int $limit): array
    {
        $query = DB::table('shopify_product_variants as spv')
            ->join('shopify_products as sp', 'sp.id', '=', 'spv.shopify_product_id')
            ->join('retail_edge_products as rep', 'rep.sku', '=', 'spv.sku')
            ->whereNull('sp.deleted_at')
            ->where(function ($q) {
                $q->whereColumn('rep.old_key', 'rep.sku')
                    ->orWhere('rep.old_key', '')
                    ->orWhereNull('rep.old_key');
            })
            ->whereNotNull('spv.sku')
            ->where('spv.sku', '!=', '')
            ->select('spv.sku', DB::raw('COUNT(DISTINCT spv.shopify_product_id) as instances'))
            ->groupBy('spv.sku')
            ->having('instances', '>', 1);

        if ($targetSku) {
            $query->where('spv.sku', $targetSku);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->toArray();
    }

    private function processParentSku(string $parentSku, bool $isDryRun): void
    {
        $this->newLine();
        $this->line("  Processing parent SKU: {$parentSku}");

        $candidates = DB::table('shopify_product_variants as spv')
            ->join('shopify_products as sp', 'sp.id', '=', 'spv.shopify_product_id')
            ->whereNull('sp.deleted_at')
            ->whereNotNull('sp.product_id')
            ->where('spv.sku', $parentSku)
            ->select('sp.id as pid', 'sp.product_id', 'sp.title', 'sp.created_at')
            ->distinct()
            ->get();

        if ($candidates->count() < 2) {
            $this->info('    SKIP: only one live product remaining');
            $this->stats['skipped']++;

            return;
        }

        $scored = [];
        foreach ($candidates as $c) {
            $scored[] = [
                'pid' => (int) $c->pid,
                'product_id' => (int) $c->product_id,
                'title' => $c->title,
                'created_at' => $c->created_at,
                'score' => $this->scoreCandidate((int) $c->pid, $parentSku),
            ];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            $cmp = strcmp((string) $a['created_at'], (string) $b['created_at']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $a['product_id'] <=> $b['product_id'];
        });

        $keep = $scored[0];
        $toDelete = array_slice($scored, 1);

        $this->line("    Candidates: {$candidates->count()}");
        foreach ($scored as $s) {
            $tag = $s['pid'] === $keep['pid'] ? 'KEEP   ' : 'DELETE ';
            $titlePreview = substr((string) $s['title'], 0, 50);
            $this->line("      [{$tag}] product_id={$s['product_id']} score={$s['score']} created={$s['created_at']} title=\"{$titlePreview}\"");
        }

        $this->stats['kept']++;

        foreach ($toDelete as $d) {
            $this->deleteOne($d, $parentSku, $isDryRun);
        }
    }

    /**
     * Score = number of variants on this product whose SKU is a legitimate
     * child of $parentSku per RetailEdge (rep.old_key = $parentSku). The
     * parent's own self-variant (sku = old_key = parentSku) is included.
     */
    private function scoreCandidate(int $shopifyProductsId, string $parentSku): int
    {
        return DB::table('shopify_product_variants as spv')
            ->join('retail_edge_products as rep', 'rep.sku', '=', 'spv.sku')
            ->where('spv.shopify_product_id', $shopifyProductsId)
            ->where('rep.old_key', $parentSku)
            ->count();
    }

    private function deleteOne(array $d, string $parentSku, bool $isDryRun): void
    {
        if ($isDryRun) {
            $variantsCount = ShopifyProductVariant::where('shopify_product_id', $d['pid'])->count();
            $this->line("        [DRY RUN] would delete product_id={$d['product_id']} ({$variantsCount} mirrored variants)");

            return;
        }

        try {
            $this->deleteProductFromShopify($d['product_id']);
            $this->cascadeLocalCleanup($d['pid']);
            $this->stats['products_deleted']++;

            $this->info("        Deleted product_id={$d['product_id']} successfully");

            Log::info('DeleteDuplicateProducts: deleted duplicate parent product', [
                'parent_sku' => $parentSku,
                'shopify_product_id' => $d['product_id'],
                'score' => $d['score'],
            ]);
        } catch (\Throwable $e) {
            $this->handleDeletionError($e, $d, $parentSku);
        }

        usleep(100_000);
    }

    /**
     * Hard-delete the local mirror rows for a Shopify product. Per-variant
     * cleanup runs through ShopifyCleanupTrait so the uploaded_to_shopify
     * flag on orphaned SKUs gets reset only when no other Shopify variant
     * row references that SKU.
     */
    private function cascadeLocalCleanup(int $shopifyProductsId): void
    {
        $variants = ShopifyProductVariant::where('shopify_product_id', $shopifyProductsId)->get();
        foreach ($variants as $v) {
            $this->cleanupStaleVariant($v, 'DeleteDuplicateProducts');
            $this->stats['variants_cascaded']++;
        }
        ShopifyProduct::where('id', $shopifyProductsId)->forceDelete();
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

    private function handleDeletionError(\Throwable $e, array $d, string $parentSku): void
    {
        $message = $e->getMessage();

        if ($this->isResourceNotFoundError($message)) {
            $this->warn('        Not found on Shopify — cleaning local mirror only');
            $this->cascadeLocalCleanup($d['pid']);
            $this->stats['products_deleted']++;

            Log::info('DeleteDuplicateProducts: cleaned stale local mirror (Shopify product gone)', [
                'parent_sku' => $parentSku,
                'shopify_product_id' => $d['product_id'],
            ]);

            return;
        }

        $this->error("        Failed: {$message}");
        $this->stats['errors']++;

        Log::error('DeleteDuplicateProducts: deletion failed', [
            'parent_sku' => $parentSku,
            'shopify_product_id' => $d['product_id'],
            'error' => $message,
        ]);
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
        $this->info("  Duplicate parent SKUs found: {$this->stats['duplicate_parents_found']}");
        $this->info("  Products kept: {$this->stats['kept']}");
        $this->info("  Skipped (already single-instance): {$this->stats['skipped']}");

        if ($isDryRun) {
            $this->warn('  [DRY RUN] no changes were made');
        } else {
            $this->info("  Products deleted from Shopify: {$this->stats['products_deleted']}");
            $this->info("  Variant rows cascaded (local mirror): {$this->stats['variants_cascaded']}");
            $this->info("  Errors: {$this->stats['errors']}");
        }
    }
}
