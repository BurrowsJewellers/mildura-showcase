<?php

namespace App\Console\Commands\Shopify;

use App\Models\EWeb\RetailEdgeProduct;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\GraphQL\ProductMutations;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateProductGraphQL extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:create-product-graphql';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create products in Shopify using GraphQL API';

    protected ShopifyGraphQLService $graphqlService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyCreateProductGraphQL';

        $job = (new SyncJobService)->getJob($jobType, $marketplace);

        if (! $job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $this->graphqlService = new ShopifyGraphQLService;

                // Get pending products
                $pendingProducts = DB::select('
                    SELECT rep.id, rep.sku
                    FROM retail_edge_products rep
                    LEFT JOIN shopify_product_variants spv ON rep.sku = spv.sku
                    WHERE spv.id IS NULL
                ');

                $pendingProductIds = array_column($pendingProducts, 'id');

                if (empty($pendingProductIds)) {
                    $this->info('No pending products to create.');
                    $job->update(['status' => 0, 'message' => null]);

                    return;
                }

                // Process products
                $countQuery = RetailEdgeProduct::whereIn('id', $pendingProductIds)
                    ->where('uploaded_to_shopify', 0)
                    ->where('quantity', '>', 0);

                $count = $countQuery->count();

                while ($count) {
                    $this->info("Remaining products to create: {$count}");

                    $product = RetailEdgeProduct::with(['brand'])
                        ->where('uploaded_to_shopify', 0)
                        ->where('quantity', '>', 0)
                        ->first();

                    if ($product) {
                        $this->createProductInShopify($product);
                        usleep(1500000); // Rate limiting
                    }

                    $count = $countQuery->count();
                }

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
     * Create a product in Shopify using GraphQL
     */
    protected function createProductInShopify(RetailEdgeProduct $product)
    {
        try {
            $this->info("Creating product: {$product->title} (SKU: {$product->sku})");

            // Prepare variant data
            $retailPrices = [$product->retail_price1, $product->retail_price2];
            $prices = array_filter(array_map('floatval', $retailPrices), function ($price) {
                return $price > 0;
            });

            $price = ! empty($prices) ? min($prices) : 0;
            $compareAtPrice = ! empty($prices) ? max($prices) : 0;

            if ($price == $compareAtPrice) {
                $compareAtPrice = null; // Don't set compare price if same as regular price
            }

            // Build product input (GraphQL format - no variants here)
            $productInput = [
                'title' => $product->title,
                'descriptionHtml' => $product->marketing_description ?? '',
                'vendor' => $product->brand?->name ?? '',
                'productType' => $product->s_cat ?? '',
                'tags' => $this->calculateTags($product),
                'status' => 'ACTIVE', // GraphQL enum (REST equivalent: 'active')
            ];

            // Step 1: Create the product (without variants)
            $response = $this->graphqlService->mutate(
                ProductMutations::createProduct(),
                ['input' => $productInput]
            );

            if (! isset($response['productCreate']['product'])) {
                // Handle product creation errors
                $errors = [];
                if (isset($response['productCreate']['userErrors'])) {
                    $errors = array_map(function ($error) {
                        return $error['message'];
                    }, $response['productCreate']['userErrors']);
                }

                $errorMessage = ! empty($errors) ? implode(', ', $errors) : 'Unknown error creating product';
                $this->error("Failed to create product: {$errorMessage}");
                Log::error("Error creating product {$product->sku}: {$errorMessage}");
                $product->update(['uploaded_to_shopify' => 2]);

                foreach ($product->children as $child) {
                    $child->update(['uploaded_to_shopify' => 2]);
                }

                return;
            }

            $createdProduct = $response['productCreate']['product'];
            $productGid = $createdProduct['id'];

            // Step 2: Create the variant separately (matching REST values)
            $variantInput = [
                'productId' => $productGid,
                'sku' => $product->sku,
                'price' => (string) $price,
                'barcode' => $product->barcode,  // Match REST: direct value, no null coalescing
                'inventoryPolicy' => 'DENY', // Match REST default
                'inventoryManagement' => 'SHOPIFY', // Match REST: 'shopify' -> 'SHOPIFY' (GraphQL format)
                'taxable' => true, // Match REST default
                'weight' => 0, // Match REST default
                'weightUnit' => 'POUNDS', // Match REST default 'lb' -> 'POUNDS' (GraphQL format)
                'requiresShipping' => true, // Match REST default
            ];

            if ($compareAtPrice && $compareAtPrice != $price) {
                $variantInput['compareAtPrice'] = (string) $compareAtPrice;
            }

            $variantResponse = $this->graphqlService->mutate(
                ProductMutations::createProductVariant(),
                ['input' => $variantInput]
            );

            if (! isset($variantResponse['productVariantCreate']['productVariant'])) {
                // Handle variant creation errors
                $errors = [];
                if (isset($variantResponse['productVariantCreate']['userErrors'])) {
                    $errors = array_map(function ($error) {
                        return $error['message'];
                    }, $variantResponse['productVariantCreate']['userErrors']);
                }

                $errorMessage = ! empty($errors) ? implode(', ', $errors) : 'Unknown error creating variant';
                $this->error("Failed to create variant: {$errorMessage}");
                Log::error("Error creating variant for {$product->sku}: {$errorMessage}");
                $product->update(['uploaded_to_shopify' => 2]);

                foreach ($product->children as $child) {
                    $child->update(['uploaded_to_shopify' => 2]);
                }

                return;
            }

            // Step 3: Save to database
            $createdVariant = $variantResponse['productVariantCreate']['productVariant'];
            $this->saveCreatedProductAndVariant($createdProduct, $createdVariant);

            $product->update(['uploaded_to_shopify' => 1]);

            // Update children products as uploaded (matching REST logic)
            foreach ($product->children as $child) {
                $child->update(['uploaded_to_shopify' => 1]);
            }

            $this->info("Product created successfully: {$product->title}");
            Log::info("Shopify product {$product->sku} created via GraphQL");

        } catch (\Exception $e) {
            $this->error("Error creating product {$product->sku}: ".$e->getMessage());
            Log::error("Exception creating product {$product->sku}: ".$e->getMessage());
            $product->update(['uploaded_to_shopify' => 2]);
            report($e);
        }
    }

    /**
     * Save created product and variant to database (GraphQL approach)
     */
    protected function saveCreatedProductAndVariant(array $productData, array $variantData)
    {
        try {
            DB::beginTransaction();

            // Extract REST IDs
            $productId = $this->graphqlService->extractRestId($productData['id']);
            $variantId = $this->graphqlService->extractRestId($variantData['id']);

            // Create product
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

            // Create variant (matching REST ShopifyService.saveProductToDb values)
            $shopifyProductVariant = ShopifyProductVariant::create([
                'shopify_product_id' => $shopifyProduct->id,
                'sku' => $variantData['sku'], // From GraphQL response
                'variant_id' => $variantId,
                'product_id' => $productId,
                'title' => $variantData['title'] ?? 'Default Title', // Shopify default
                'price' => $variantData['price'],
                'compare_at_price' => $variantData['compareAtPrice'] ? $variantData['compareAtPrice'] : 0,
                'position' => 1, // Default position for first variant
                'inventory_policy' => strtolower($variantData['inventoryPolicy'] ?? 'deny'), // Match REST format
                'fulfillment_service' => 'manual', // Match REST default
                'inventory_management' => strtolower($variantData['inventoryManagement'] ?? 'shopify'), // Match REST
                'option1' => 'Default Title', // Shopify default for single variant products
                'option2' => null,
                'option3' => null,
                'taxable' => $variantData['taxable'] ?? true, // Match REST default
                'barcode' => $variantData['barcode'],
                'grams' => $variantData['weight'] ?? 0, // In grams (match REST)
                'weight' => $variantData['weight'] ? $variantData['weight'] / 453.592 : 0, // Convert grams to pounds (REST format)
                'inventory_item_id' => null, // Will be populated by Shopify
                'inventory_quantity' => 0, // Default
                'old_inventory_quantity' => 0, // Default
                'requires_shipping' => $variantData['requiresShipping'] ?? true, // Match REST default
                'price_requires_update' => 1, // Match REST new variant flags
                'inventory_requires_update' => 1, // Match REST new variant flags
                'images_requires_update' => 1, // Match REST new variant flags
            ]);

            if ($shopifyProductVariant && $shopifyProductVariant->sku) {
                \App\Models\EWeb\RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)
                    ->update(['uploaded_to_shopify' => 1]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Save created product to database - matching exact REST logic (LEGACY - not used in GraphQL)
     */
    protected function saveCreatedProductToDb(array $productData)
    {
        try {
            DB::beginTransaction();

            // Extract REST product ID
            $productId = $this->graphqlService->extractRestId($productData['id']);

            // Process variants first (matching original REST logic)
            if (isset($productData['variants']['nodes'])) {
                foreach ($productData['variants']['nodes'] as $variantData) {
                    $variantId = $this->graphqlService->extractRestId($variantData['id']);

                    if ($shopifyProductVariant = ShopifyProductVariant::where('variant_id', $variantId)->first()) {
                        // Update existing variant case (matching REST)
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

                        $this->updateExistingVariant($shopifyProductVariant, $variantData, $productId);
                    } else {
                        // Create new variant case (matching REST)
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

                        $this->createNewVariant($shopifyProduct, $variantData, $productId);
                    }
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update existing variant (matching REST logic)
     */
    protected function updateExistingVariant($shopifyProductVariant, array $variantData, string $productId)
    {
        $option1 = $option2 = $option3 = null;
        if (isset($variantData['selectedOptions'])) {
            foreach ($variantData['selectedOptions'] as $index => $option) {
                ${'option'.($index + 1)} = $option['value'] ?? null;
            }
        }

        $inventoryItemId = isset($variantData['inventoryItem']['id']) ?
            $this->graphqlService->extractRestId($variantData['inventoryItem']['id']) : null;

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
            'inventory_quantity' => $variantData['inventoryQuantity'] ?? 0,
            'old_inventory_quantity' => $variantData['oldInventoryQuantity'] ?? 0,
            'requires_shipping' => $variantData['requiresShipping'] ?? true,
        ]);

        if ($shopifyProductVariant && $shopifyProductVariant->sku) {
            \App\Models\EWeb\RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)
                ->update(['uploaded_to_shopify' => 1]);
        }
    }

    /**
     * Create new variant (matching REST logic)
     */
    protected function createNewVariant($shopifyProduct, array $variantData, string $productId)
    {
        $variantId = $this->graphqlService->extractRestId($variantData['id']);

        $option1 = $option2 = $option3 = null;
        if (isset($variantData['selectedOptions'])) {
            foreach ($variantData['selectedOptions'] as $index => $option) {
                ${'option'.($index + 1)} = $option['value'] ?? null;
            }
        }

        $inventoryItemId = isset($variantData['inventoryItem']['id']) ?
            $this->graphqlService->extractRestId($variantData['inventoryItem']['id']) : null;

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
            'inventory_quantity' => $variantData['inventoryQuantity'] ?? 0,
            'old_inventory_quantity' => isset($variantData['oldInventoryQuantity']) ? $variantData['oldInventoryQuantity'] : 0,
            'requires_shipping' => $variantData['requiresShipping'] ?? true,
            'price_requires_update' => 1,
            'inventory_requires_update' => 1,
            'images_requires_update' => 1,
        ]);

        if ($shopifyProductVariant && $shopifyProductVariant->sku) {
            \App\Models\EWeb\RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)
                ->update(['uploaded_to_shopify' => 1]);
        }
    }

    /**
     * Calculate tags for the product
     */
    private function calculateTags(RetailEdgeProduct $product): array
    {
        $tags = [];

        try {
            $types = [
                's_web_menu' => 'S.WebMenu',
                's_metal_type' => 'S.Metal Type',
                's_stone_type' => 'S.Stone Type',
                's_cat' => 'S.Cat',
                's_sub_cat' => 'S.Sub Cat',
            ];

            foreach ($types as $type => $value) {
                $propValue = $product->{$type} ?? '';
                if ($propValue !== '' && $propValue !== 'N/A') {
                    foreach (explode(',', $propValue) as $tempTag) {
                        $tags[] = $value.'_'.trim($tempTag);
                    }
                }
            }
        } catch (\Exception $e) {
            report($e);

            return [];
        }

        return $tags;
    }
}
