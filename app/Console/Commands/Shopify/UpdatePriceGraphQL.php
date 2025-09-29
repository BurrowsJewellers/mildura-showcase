<?php

namespace App\Console\Commands\Shopify;

use App\Models\Shopify\ShopifyProductVariant;
use App\Services\GraphQL\ProductMutations;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePriceGraphQL extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:update-price-graphql';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update product prices in Shopify using GraphQL API';

    protected ShopifyGraphQLService $graphqlService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdatePriceGraphQL';

        $job = (new SyncJobService)->getJob($jobType, $marketplace);

        if (! $job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $this->graphqlService = new ShopifyGraphQLService;

                // Process price updates in batches
                $this->processPriceUpdates();

                $job->update(['status' => 0, 'message' => null]);
                Log::info("$marketplace $jobType finished!");

            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                report($e);
                $this->error($e->getMessage());
            }
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }

    /**
     * Process price updates in batches by product
     */
    protected function processPriceUpdates()
    {
        // Get variants that need price updates, grouped by product
        $variantsToUpdate = ShopifyProductVariant::with('product')
            ->whereNotNull('variant_id')
            ->where('price_requires_update', 1)
            ->get()
            ->groupBy('product_id');

        $totalProducts = $variantsToUpdate->count();
        $this->info("Total products with price updates: {$totalProducts}");

        foreach ($variantsToUpdate as $productId => $variants) {
            if ($variants->isEmpty()) {
                continue;
            }

            // Prepare bulk update for all variants of this product
            $this->updateProductVariantPrices($productId, $variants);

            usleep(1000000); // Rate limiting - 1 second between products
        }
    }

    /**
     * Update prices for all variants of a product using bulk mutation
     */
    protected function updateProductVariantPrices($productId, $variants)
    {
        try {
            $productGid = $this->graphqlService->formatGraphQLId('Product', $productId);

            // Prepare variants input for bulk update
            $variantsInput = [];

            foreach ($variants as $variant) {
                $variantGid = $this->graphqlService->formatGraphQLId('ProductVariant', $variant->variant_id);

                $variantInput = [
                    'id' => $variantGid,
                    'price' => (string) $variant->price,
                ];

                // Only add compareAtPrice if it's different from price and greater than 0
                if ($variant->compare_at_price && $variant->compare_at_price != $variant->price) {
                    $variantInput['compareAtPrice'] = (string) $variant->compare_at_price;
                } else {
                    $variantInput['compareAtPrice'] = null;
                }

                $variantsInput[] = $variantInput;
            }

            // Execute bulk update mutation
            $response = $this->graphqlService->mutate(
                ProductMutations::bulkUpdateVariants(),
                [
                    'productId' => $productGid,
                    'variants' => $variantsInput,
                ]
            );

            if (isset($response['productVariantsBulkUpdate']['product'])) {
                // Mark all variants as successfully updated
                foreach ($variants as $variant) {
                    $variant->update([
                        'price' => $variant->price,
                        'compare_at_price' => $variant->compare_at_price,
                        'price_requires_update' => 0,
                    ]);

                    $this->info("Price updated for SKU: {$variant->sku} (Price: {$variant->price}, Compare: {$variant->compare_at_price})");
                }

                Log::info("Bulk price update successful for product ID: {$productId}");
            } else {
                // Handle errors
                if (isset($response['productVariantsBulkUpdate']['userErrors']) &&
                    ! empty($response['productVariantsBulkUpdate']['userErrors'])) {

                    $errors = array_map(function ($error) {
                        return isset($error['message']) ? $error['message'] : 'Unknown error';
                    }, $response['productVariantsBulkUpdate']['userErrors']);

                    $errorMessage = implode(', ', $errors);
                    $this->error("Failed to update prices for product {$productId}: {$errorMessage}");
                    Log::error("Error updating prices for product {$productId}: {$errorMessage}");

                    // Mark variants as failed
                    foreach ($variants as $variant) {
                        $variant->update(['price_requires_update' => 2]);
                    }
                }
            }

        } catch (\Exception $e) {
            $this->error("Error updating prices for product {$productId}: ".$e->getMessage());
            Log::error("Exception updating prices for product {$productId}: ".$e->getMessage());

            // Mark all variants as failed
            foreach ($variants as $variant) {
                $variant->update(['price_requires_update' => 2]);
            }
        }
    }
}
