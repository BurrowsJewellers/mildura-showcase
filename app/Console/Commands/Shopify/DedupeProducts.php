<?php

namespace App\Console\Commands\Shopify;

use App\Models\Shopify\ShopifyProduct;
use App\Services\GraphQL\ProductMutations;
use App\Services\GraphQL\ProductQueries;
use App\Services\ShopifyGraphQLService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DedupeProducts extends Command
{
    protected $signature = 'shopify:dedupe-products {--apply : Archive duplicates (default is dry-run)}';

    protected $description = 'Find Shopify products that share a SKU with another product, keep one, archive the rest.';

    protected ShopifyGraphQLService $graphqlService;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->graphqlService = new ShopifyGraphQLService;

        $this->info($apply ? 'Mode: APPLY (archives will run)' : 'Mode: DRY RUN (no changes)');

        $bySku = $this->collectProductsBySku();

        $duplicates = $bySku->filter(fn ($products) => count($products) > 1);

        if ($duplicates->isEmpty()) {
            $this->info('No duplicate SKUs found.');

            return self::SUCCESS;
        }

        $this->info("Found {$duplicates->count()} SKUs with duplicates.");

        $archivedCount = 0;
        $rows = [['sku', 'keeper_product_id', 'archived_product_ids']];

        foreach ($duplicates as $sku => $products) {
            $sorted = $this->rankCandidates($products);
            $keeper = array_shift($sorted);
            $losers = $sorted;

            $rows[] = [
                $sku,
                $keeper['rest_id'],
                implode('|', array_column($losers, 'rest_id')),
            ];

            foreach ($losers as $loser) {
                if ($apply) {
                    if ($this->archiveProduct($loser)) {
                        $archivedCount++;
                    }
                    usleep(500000);
                }
            }
        }

        $this->table($rows[0], array_slice($rows, 1));

        if ($apply) {
            $this->info("Archived {$archivedCount} duplicate products.");
        } else {
            $this->warn('Dry run complete. Re-run with --apply to archive losers.');
        }

        return self::SUCCESS;
    }

    /**
     * Walk every Shopify product, group by SKU. SKU is read from the
     * inventoryItem subtree (its current location from API 2024-04+).
     */
    protected function collectProductsBySku(): \Illuminate\Support\Collection
    {
        $bySku = collect();

        $this->graphqlService->paginate(
            ProductQueries::getProductsForDedupe(),
            ['first' => 100],
            function (array $product) use ($bySku) {
                $skus = collect($product['variants']['nodes'] ?? [])
                    ->map(fn ($variant) => $variant['inventoryItem']['sku'] ?? null)
                    ->filter()
                    ->unique();

                foreach ($skus as $sku) {
                    $bySku[$sku] = $bySku->get($sku, []);
                    $bySku[$sku][] = [
                        'gid' => $product['id'],
                        'rest_id' => $this->graphqlService->extractRestId($product['id']),
                        'title' => $product['title'] ?? '',
                        'created_at' => $product['createdAt'] ?? null,
                        'status' => $product['status'] ?? null,
                        'total_inventory' => (int) ($product['totalInventory'] ?? 0),
                        'has_media' => ! empty($product['media']['nodes'] ?? []),
                    ];
                }
            },
            'products'
        );

        return $bySku;
    }

    /**
     * Rank candidates so the first element is the keeper. Priority order:
     *
     *   1. Non-archived first (ACTIVE/DRAFT outranks ARCHIVED).
     *   2. Has media (so we don't lose the variant with images).
     *   3. Higher totalInventory (the one currently selling).
     *   4. Older createdAt (the original product, since this is a dedupe).
     *   5. Lower REST id (stable tiebreaker — without this, two products
     *      that match on every other dimension can flip between runs).
     */
    protected function rankCandidates(array $products): array
    {
        usort($products, function ($a, $b) {
            $byArchived = ($a['status'] === 'ARCHIVED') <=> ($b['status'] === 'ARCHIVED');
            if ($byArchived !== 0) {
                return $byArchived;
            }

            $byMedia = ((int) $b['has_media']) <=> ((int) $a['has_media']);
            if ($byMedia !== 0) {
                return $byMedia;
            }

            $byInventory = $b['total_inventory'] <=> $a['total_inventory'];
            if ($byInventory !== 0) {
                return $byInventory;
            }

            $byCreated = strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
            if ($byCreated !== 0) {
                return $byCreated;
            }

            return ((int) ($a['rest_id'] ?? 0)) <=> ((int) ($b['rest_id'] ?? 0));
        });

        return $products;
    }

    protected function archiveProduct(array $product): bool
    {
        try {
            $response = $this->graphqlService->mutate(
                ProductMutations::updateProductStatus(),
                [
                    'product' => [
                        'id' => $product['gid'],
                        'status' => 'ARCHIVED',
                    ],
                ]
            );

            if (isset($response['productUpdate']['product'])) {
                ShopifyProduct::where('product_id', $product['rest_id'])
                    ->update(['status' => 'archived']);

                $this->info("Archived: {$product['title']} ({$product['rest_id']})");

                return true;
            }

            $this->warn("Archive returned no product for {$product['rest_id']}");

            return false;
        } catch (\Exception $e) {
            Log::warning("Failed to archive {$product['rest_id']}: ".$e->getMessage());
            $this->error("Failed to archive {$product['rest_id']}: ".$e->getMessage());

            return false;
        }
    }
}
