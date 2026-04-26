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

        $job = (new SyncJobService)->claim($jobType, $marketplace);

        if (! $job) {
            Log::info("$marketplace $jobType is already running.");

            return;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $this->graphqlService = new ShopifyGraphQLService;

            $this->getLocations();
            $this->getProducts();

            $job->update(['status' => 0, 'message' => null]);
            Log::info("$marketplace $jobType finished!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            report($e);
            $this->error($e->getMessage());
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
     * Save variant data to database. Reads sku, requiresShipping, weight,
     * tracked from the inventoryItem subtree — those fields were moved off
     * ProductVariant in API 2024-04 and removed from the variant in 2026-04.
     */
    protected function saveVariantToDb(ShopifyProduct $shopifyProduct, array $variantData, string $productId)
    {
        $variantId = $this->graphqlService->extractRestId($variantData['id']);

        $options = [null, null, null];
        foreach (($variantData['selectedOptions'] ?? []) as $index => $option) {
            if ($index < 3) {
                $options[$index] = $option['value'] ?? null;
            }
        }
        [$option1, $option2, $option3] = $options;

        $inventoryItem = $variantData['inventoryItem'] ?? [];
        $inventoryItemId = isset($inventoryItem['id'])
            ? $this->graphqlService->extractRestId($inventoryItem['id'])
            : null;
        $sku = $inventoryItem['sku'] ?? null;
        $requiresShipping = $inventoryItem['requiresShipping'] ?? true;

        $weightValue = $inventoryItem['measurement']['weight']['value'] ?? null;
        $weightUnit = $inventoryItem['measurement']['weight']['unit'] ?? null;
        $weightInKg = $this->normalizeWeightToKg($weightValue, $weightUnit);

        $inventoryQuantity = $variantData['inventoryQuantity'] ?? 0;

        foreach (($inventoryItem['inventoryLevels']['nodes'] ?? []) as $inventoryLevel) {
            if (! $inventoryItemId || ! isset($inventoryLevel['location']['id'])) {
                continue;
            }

            $locationId = $this->graphqlService->extractRestId($inventoryLevel['location']['id']);
            $available = $this->extractAvailableQuantity($inventoryLevel);

            ShopifyInventoryLevel::updateOrCreate(
                [
                    'location_id' => $locationId,
                    'inventory_item_id' => $inventoryItemId,
                ],
                [
                    'available' => $available,
                    'inventory_updated_at' => Carbon::parse($inventoryLevel['updatedAt'] ?? now()),
                ]
            );
        }

        if ($shopifyProductVariant = ShopifyProductVariant::where('variant_id', $variantId)->first()) {
            $shopifyProductVariant->update([
                'product_id' => $productId,
                'title' => $variantData['title'],
                'price' => $variantData['price'] ?? 0,
                'position' => $variantData['position'] ?? 1,
                'inventory_policy' => strtolower($variantData['inventoryPolicy'] ?? 'deny'),
                'fulfillment_service' => 'manual',
                'inventory_management' => 'shopify',
                'option1' => $option1,
                'option2' => $option2,
                'option3' => $option3,
                'taxable' => $variantData['taxable'] ?? true,
                'barcode' => $variantData['barcode'] ?? null,
                'grams' => $weightInKg ? (int) round($weightInKg * 1000) : 0,
                'weight' => $weightInKg ?? 0,
                'inventory_item_id' => $inventoryItemId,
                'inventory_quantity' => $inventoryQuantity,
                'old_inventory_quantity' => $inventoryQuantity,
                'requires_shipping' => $requiresShipping,
            ]);
        } else {
            $shopifyProductVariant = ShopifyProductVariant::create([
                'shopify_product_id' => $shopifyProduct->id,
                'sku' => $sku,
                'variant_id' => $variantId,
                'product_id' => $productId,
                'title' => $variantData['title'],
                'price' => $variantData['price'] ?? 0,
                'compare_at_price' => $variantData['compareAtPrice'] ?: 0,
                'position' => $variantData['position'] ?? 1,
                'inventory_policy' => strtolower($variantData['inventoryPolicy'] ?? 'deny'),
                'fulfillment_service' => 'manual',
                'inventory_management' => 'shopify',
                'option1' => $option1,
                'option2' => $option2,
                'option3' => $option3,
                'taxable' => $variantData['taxable'] ?? true,
                'barcode' => $variantData['barcode'] ?? null,
                'grams' => $weightInKg ? (int) round($weightInKg * 1000) : 0,
                'weight' => $weightInKg ?? 0,
                'inventory_item_id' => $inventoryItemId,
                'inventory_quantity' => $inventoryQuantity,
                'old_inventory_quantity' => 0,
                'requires_shipping' => $requiresShipping,
                'price_requires_update' => 1,
                'inventory_requires_update' => 1,
                'images_requires_update' => 1,
            ]);
        }

        if ($shopifyProductVariant && $shopifyProductVariant->sku) {
            \App\Models\EWeb\RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)
                ->update(['uploaded_to_shopify' => 1]);
        }
    }

    /**
     * Read the available quantity from a Shopify inventoryLevel node.
     * The 2024-10+ shape returns quantities[] with name/quantity pairs.
     */
    private function extractAvailableQuantity(array $inventoryLevel): int
    {
        foreach (($inventoryLevel['quantities'] ?? []) as $entry) {
            if (($entry['name'] ?? null) === 'available') {
                return (int) ($entry['quantity'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Convert a Shopify Weight (value + WeightUnit enum) to kilograms.
     * Shopify returns one of: GRAMS, KILOGRAMS, OUNCES, POUNDS.
     */
    private function normalizeWeightToKg(?float $value, ?string $unit): ?float
    {
        if ($value === null || $unit === null) {
            return null;
        }

        return match ($unit) {
            'GRAMS' => $value / 1000,
            'KILOGRAMS' => $value,
            'OUNCES' => $value * 0.0283495,
            'POUNDS' => $value * 0.453592,
            default => $value,
        };
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
