<?php

namespace App\Console\Commands\Shopify;

use App\Models\Shopify\ShopifyInventoryLevel;
use App\Models\Shopify\ShopifyLocation;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\GraphQL\ProductQueries;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GetProductsGraphQL extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:get-products-graphql';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get products from Shopify using GraphQL API';

    protected ShopifyGraphQLService $graphqlService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyGetProductsGraphQL';

        $job = (new SyncJobService)->getJob($jobType, $marketplace);

        if (! $job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $this->graphqlService = new ShopifyGraphQLService;

                // Get Shopify locations
                $this->getLocations();

                // Get Shopify products with inventory
                $this->getProducts();

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
     * Get locations using GraphQL
     */
    protected function getLocations()
    {
        try {
            $this->info('Fetching locations...');

            $response = $this->graphqlService->query(
                ProductQueries::getLocations(),
                ['first' => 10]
            );

            if (isset($response['locations']['nodes'])) {
                foreach ($response['locations']['nodes'] as $locationData) {
                    try {
                        // Extract REST ID from GraphQL ID
                        $locationId = $this->graphqlService->extractRestId($locationData['id']);

                        ShopifyLocation::updateOrCreate(
                            [
                                'location_id' => $locationId,
                            ],
                            [
                                'name' => $locationData['name'],
                                'address1' => $locationData['address']['address1'] ?? null,
                                'address2' => $locationData['address']['address2'] ?? null,
                                'city' => $locationData['address']['city'] ?? null,
                                'zip' => $locationData['address']['zip'] ?? null,
                                'province' => $locationData['address']['province'] ?? null,
                                'country' => $locationData['address']['country'] ?? null,
                                'phone' => $locationData['address']['phone'] ?? null,
                                'country_code' => $locationData['address']['countryCode'] ?? null,
                                'country_name' => $locationData['address']['country'] ?? null,
                                'province_code' => $locationData['address']['provinceCode'] ?? null,
                                'active' => $locationData['isActive'],
                            ]
                        );

                        $this->info("Location saved: {$locationData['name']}");
                    } catch (\Exception $e) {
                        $this->error('Error saving location: '.$e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Get products using GraphQL with pagination
     */
    protected function getProducts()
    {
        try {
            $this->info('Fetching products...');
            $productIds = [];
            $processedCount = 0;

            $this->graphqlService->paginate(
                ProductQueries::getProducts(),
                ['first' => 50], // Fetch 50 products at a time
                function ($productData) use (&$productIds, &$processedCount) {
                    try {
                        $productId = $this->graphqlService->extractRestId($productData['id']);
                        $productIds[] = $productId;

                        $this->saveProductToDb($productData);
                        $processedCount++;
                        $this->info("Processed product #{$processedCount}: {$productData['title']}");
                    } catch (\Exception $e) {
                        $this->error('Error processing product: '.$e->getMessage());
                    }
                },
                'products'
            );

            $this->info("Total products processed: {$processedCount}");

            // Clean up products that no longer exist in Shopify
            if (! empty($productIds)) {
                $this->deleteRemovedProducts($productIds);
            }

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Save product data to database
     */
    protected function saveProductToDb(array $productData)
    {
        try {
            DB::beginTransaction();

            // Extract REST product ID
            $productId = $this->graphqlService->extractRestId($productData['id']);

            // Create or update the product
            $shopifyProduct = ShopifyProduct::updateOrCreate(
                [
                    'product_id' => $productId,
                ],
                [
                    'title' => $productData['title'],
                    'vendor' => $productData['vendor'] ?? null,
                    'product_type' => $productData['productType'] ?? null,
                    'handle' => $productData['handle'],
                    'tags' => implode(',', $productData['tags'] ?? []),
                    'status' => strtolower($productData['status']),
                ]
            );

            // Process variants
            if (isset($productData['variants']['nodes'])) {
                foreach ($productData['variants']['nodes'] as $variantData) {
                    $this->saveVariantToDb($shopifyProduct, $variantData, $productId);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Save variant data to database
     */
    protected function saveVariantToDb(ShopifyProduct $shopifyProduct, array $variantData, string $productId)
    {
        // Extract REST variant ID
        $variantId = $this->graphqlService->extractRestId($variantData['id']);

        // Build options from selectedOptions
        $option1 = null;
        $option2 = null;
        $option3 = null;

        if (isset($variantData['selectedOptions'])) {
            foreach ($variantData['selectedOptions'] as $index => $option) {
                $optionValue = $option['value'];
                switch ($index) {
                    case 0:
                        $option1 = $optionValue;
                        break;
                    case 1:
                        $option2 = $optionValue;
                        break;
                    case 2:
                        $option3 = $optionValue;
                        break;
                }
            }
        }

        // Extract inventory item ID
        $inventoryItemId = null;
        if (isset($variantData['inventoryItem']['id'])) {
            $inventoryItemId = $this->graphqlService->extractRestId($variantData['inventoryItem']['id']);
        }

        // Get inventory quantity from first location
        $inventoryQuantity = 0;
        if (isset($variantData['inventoryItem']['inventoryLevels']['nodes'][0])) {
            $inventoryLevel = $variantData['inventoryItem']['inventoryLevels']['nodes'][0];
            $inventoryQuantity = $inventoryLevel['available'] ?? 0;

            // Save inventory level
            if ($inventoryItemId && isset($inventoryLevel['location']['id'])) {
                $locationId = $this->graphqlService->extractRestId($inventoryLevel['location']['id']);
                ShopifyInventoryLevel::updateOrCreate(
                    [
                        'location_id' => $locationId,
                        'inventory_item_id' => $inventoryItemId,
                    ],
                    [
                        'available' => $inventoryQuantity,
                        'inventory_updated_at' => Carbon::parse($inventoryLevel['updatedAt'] ?? now()),
                    ]
                );
            }
        }

        // Check if variant exists (matching original REST logic)
        if ($shopifyProductVariant = ShopifyProductVariant::where('variant_id', $variantId)->first()) {
            // Update existing variant
            $shopifyProductVariant->update([
                'product_id' => $productId,
                'title' => $variantData['title'],
                'price' => $variantData['price'] ?? 0,
                'position' => $variantData['position'] ?? 1,
                'inventory_policy' => strtolower($variantData['inventoryPolicy'] ?? 'deny'),
                'fulfillment_service' => $variantData['fulfillmentService'] ?? 'manual',
                'inventory_management' => $variantData['inventoryManagement'] ?? null,
                'option1' => $option1,
                'option2' => $option2,
                'option3' => $option3,
                'taxable' => $variantData['taxable'] ?? true,
                'barcode' => $variantData['barcode'] ?? null,
                'grams' => isset($variantData['weight']) ? $variantData['weight'] * 1000 : 0,
                'weight' => $variantData['weight'] ?? 0,
                'inventory_item_id' => $inventoryItemId,
                'inventory_quantity' => $inventoryQuantity,
                'old_inventory_quantity' => $inventoryQuantity,
                'requires_shipping' => $variantData['requiresShipping'] ?? true,
            ]);
        } else {
            // Create new variant - matching original REST logic
            $shopifyProductVariant = ShopifyProductVariant::create([
                'shopify_product_id' => $shopifyProduct->id,
                'sku' => $variantData['sku'] ?? null,
                'variant_id' => $variantId,
                'product_id' => $productId,
                'title' => $variantData['title'],
                'price' => $variantData['price'] ?? 0,
                'compare_at_price' => $variantData['compareAtPrice'] ? $variantData['compareAtPrice'] : 0,
                'position' => $variantData['position'] ?? 1,
                'inventory_policy' => strtolower($variantData['inventoryPolicy'] ?? 'deny'),
                'fulfillment_service' => $variantData['fulfillmentService'] ?? 'manual',
                'inventory_management' => $variantData['inventoryManagement'] ?? null,
                'option1' => $option1,
                'option2' => $option2,
                'option3' => $option3,
                'taxable' => $variantData['taxable'] ?? true,
                'barcode' => $variantData['barcode'] ?? null,
                'grams' => isset($variantData['weight']) ? $variantData['weight'] * 1000 : 0,
                'weight' => $variantData['weight'] ?? 0,
                'inventory_item_id' => $inventoryItemId,
                'inventory_quantity' => $inventoryQuantity,
                'old_inventory_quantity' => isset($variantData['old_inventory_quantity']) ? $variantData['old_inventory_quantity'] : 0,
                'requires_shipping' => $variantData['requiresShipping'] ?? true,
                'price_requires_update' => 1,
                'inventory_requires_update' => 1,
                'images_requires_update' => 1,
            ]);
        }

        // Mark as uploaded in RetailEdge products
        if ($shopifyProductVariant && $shopifyProductVariant->sku) {
            \App\Models\EWeb\RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)
                ->update(['uploaded_to_shopify' => 1]);
        }
    }

    /**
     * Delete products that no longer exist in Shopify
     */
    protected function deleteRemovedProducts(array $existingProductIds)
    {
        $shopifyProducts = ShopifyProduct::whereNotIn('product_id', $existingProductIds)
            ->with('variants')
            ->get();

        foreach ($shopifyProducts as $shopifyProduct) {
            try {
                $shopifyProduct->forceDelete();
                $this->info("Deleted product: {$shopifyProduct->title}");
                Log::info("Product deleted from DB: {$shopifyProduct->product_id}");
            } catch (\Exception $e) {
                $this->error('Error deleting product: '.$e->getMessage());
                Log::error('Error deleting Shopify product: '.$e->getMessage());
            }
        }
    }
}
