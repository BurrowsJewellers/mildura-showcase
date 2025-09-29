<?php

namespace App\Console\Commands\Shopify;

use App\Models\Shopify\ShopifyLocation;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\GraphQL\ProductMutations;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateInventoryGraphQL extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:update-inventory-graphql';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update inventory in Shopify using GraphQL API';

    protected ShopifyGraphQLService $graphqlService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdateInventoryGraphQL';

        $job = (new SyncJobService)->getJob($jobType, $marketplace);

        if (! $job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $this->graphqlService = new ShopifyGraphQLService;

                $location = ShopifyLocation::first();
                if (! $location) {
                    throw new \Exception('No Shopify location found. Please run shopify:get-products-graphql first.');
                }

                // Process inventory updates in batches
                $this->processInventoryUpdates($location);

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
     * Process inventory updates in batches
     */
    protected function processInventoryUpdates(ShopifyLocation $location)
    {
        $batchSize = 10; // Process 10 items at a time for efficiency

        $count = ShopifyProductVariant::whereNotNull('inventory_item_id')
            ->where('inventory_requires_update', 1)
            ->count();

        $this->info("Total inventory items to update: {$count}");

        while ($count > 0) {
            // Get batch of variants to update
            $variants = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])
                ->whereNotNull('inventory_item_id')
                ->where('inventory_requires_update', 1)
                ->limit($batchSize)
                ->get();

            if ($variants->isEmpty()) {
                break;
            }

            // Prepare batch update
            $quantities = [];
            $variantMap = [];

            foreach ($variants as $variant) {
                if (! $variant->retailEdgeProduct) {
                    $variant->update(['inventory_requires_update' => 2]);

                    continue;
                }

                $inventoryItemGid = $this->graphqlService->formatGraphQLId('InventoryItem', $variant->inventory_item_id);
                $locationGid = $this->graphqlService->formatGraphQLId('Location', $location->location_id);

                $quantities[] = [
                    'inventoryItemId' => $inventoryItemGid,
                    'locationId' => $locationGid,
                    'quantity' => $variant->retailEdgeProduct->quantity,
                ];

                $variantMap[$inventoryItemGid] = $variant;
            }

            if (! empty($quantities)) {
                $this->updateInventoryBatch($quantities, $variantMap);

                // Check for products that need status update
                foreach ($variants as $variant) {
                    if ($variant->retailEdgeProduct &&
                        $variant->retailEdgeProduct->quantity > 0 &&
                        $variant->product &&
                        $variant->product->status == 'archived') {
                        $this->activateProduct($variant->product);
                    }
                }
            }

            $count = ShopifyProductVariant::whereNotNull('inventory_item_id')
                ->where('inventory_requires_update', 1)
                ->count();

            $this->info("Remaining inventory items to update: {$count}");

            usleep(500000); // Rate limiting
        }
    }

    /**
     * Update inventory in batch using GraphQL
     */
    protected function updateInventoryBatch(array $quantities, array $variantMap)
    {
        try {
            $input = [
                'reason' => 'correction',
                'name' => 'Inventory sync from RetailEdge',
                'quantities' => $quantities,
            ];

            $response = $this->graphqlService->mutate(
                ProductMutations::setInventoryQuantities(),
                ['input' => $input]
            );

            if (isset($response['inventorySetQuantities']['inventoryAdjustmentGroup'])) {
                // Update variants as successful
                foreach ($variantMap as $inventoryItemGid => $variant) {
                    $quantity = 0;
                    foreach ($quantities as $q) {
                        if ($q['inventoryItemId'] === $inventoryItemGid) {
                            $quantity = $q['quantity'];
                            break;
                        }
                    }

                    $variant->update([
                        'inventory_quantity' => $quantity,
                        'inventory_requires_update' => 0,
                    ]);

                    $this->info("Inventory updated for SKU: {$variant->sku} (Qty: {$quantity})");
                }
            } else {
                // Handle errors
                if (isset($response['inventorySetQuantities']['userErrors']) &&
                    ! empty($response['inventorySetQuantities']['userErrors'])) {

                    $errors = array_map(function ($error) {
                        return $error['message'];
                    }, $response['inventorySetQuantities']['userErrors']);

                    $errorMessage = implode(', ', $errors);
                    $this->error("Failed to update inventory batch: {$errorMessage}");

                    // Mark variants as failed
                    foreach ($variantMap as $variant) {
                        $variant->update(['inventory_requires_update' => 2]);
                    }
                }
            }

        } catch (\Exception $e) {
            $this->error('Error updating inventory batch: '.$e->getMessage());
            Log::error('Exception updating inventory batch: '.$e->getMessage());

            // Mark all variants in batch as failed
            foreach ($variantMap as $variant) {
                $variant->update(['inventory_requires_update' => 2]);
            }
        }
    }

    /**
     * Activate an archived product
     */
    protected function activateProduct(ShopifyProduct $product)
    {
        try {
            $productGid = $this->graphqlService->formatGraphQLId('Product', $product->product_id);

            $input = [
                'id' => $productGid,
                'status' => 'ACTIVE', // GraphQL enum value (REST uses 'active' but GraphQL uses 'ACTIVE')
            ];

            $response = $this->graphqlService->mutate(
                ProductMutations::updateProductStatus(),
                ['input' => $input]
            );

            if (isset($response['productUpdate']['product'])) {
                $product->update(['status' => 'active']); // Match REST: lowercase in DB
                $this->info("Product activated: {$product->title}");
                Log::info("Product activated via GraphQL: {$product->title}");
            } else {
                if (isset($response['productUpdate']['userErrors']) &&
                    ! empty($response['productUpdate']['userErrors'])) {

                    $errors = array_map(function ($error) {
                        return $error['message'];
                    }, $response['productUpdate']['userErrors']);

                    $errorMessage = implode(', ', $errors);
                    $this->error("Failed to activate product: {$errorMessage}");
                    Log::error("Error activating product {$product->title}: {$errorMessage}");
                }
            }

        } catch (\Exception $e) {
            $this->error("Error activating product {$product->title}: ".$e->getMessage());
            Log::error("Exception activating product {$product->title}: ".$e->getMessage());
        }
    }
}
