<?php

namespace App\Console\Commands\Shopify;

use App\Models\EWeb\RetailEdgeProduct;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\GraphQL\ProductMutations;
use App\Services\GraphQL\ProductQueries;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateProductGraphQL extends Command
{
    protected $signature = 'shopify:create-product-graphql';

    protected $description = 'Create products in Shopify using GraphQL API';

    protected ShopifyGraphQLService $graphqlService;

    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyCreateProductGraphQL';

        $job = (new SyncJobService)->claim($jobType, $marketplace);

        if (! $job) {
            Log::info("$marketplace $jobType is already running.");

            return;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $this->graphqlService = new ShopifyGraphQLService;

            // Only consider parent/standalone rows. Child rows
            // (old_key set and != sku) belong on their parent as variants
            // and must never be created as standalone products.
            $pendingQuery = fn () => RetailEdgeProduct::with(['brand', 'children'])
                ->where('uploaded_to_shopify', 0)
                ->where('quantity', '>', 0)
                ->whereNotNull('sku')
                ->where(function ($query) {
                    $query->whereColumn('old_key', 'sku')
                        ->orWhere('old_key', '')
                        ->orWhereNull('old_key');
                })
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('shopify_product_variants')
                        ->whereColumn('shopify_product_variants.sku', 'retail_edge_products.sku');
                });

            $count = $pendingQuery()->count();

            if ($count === 0) {
                $this->info('No pending products to create.');
                $job->update(['status' => 0, 'message' => null]);

                return;
            }

            while ($count > 0) {
                $this->info("Remaining products to create: {$count}");

                $product = $pendingQuery()->first();

                if (! $product) {
                    break;
                }

                $this->createProductInShopify($product);
                usleep(1500000);

                $count = $pendingQuery()->count();
            }

            $job->update(['status' => 0, 'message' => null]);
            Log::info("$marketplace $jobType finished!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            report($e);
            $this->error($e->getMessage());
        }
    }

    /**
     * Create a product in Shopify using GraphQL.
     *
     * Flow:
     *   1. Adopt: if Shopify already has a product with this SKU, link to it
     *      locally and stop. Stops duplicate creation when a previous run
     *      created the product upstream but failed to write locally.
     *   2. productCreate (creates product + a default variant with no SKU).
     *   3. productVariantsBulkUpdate populates the default variant: sku
     *      (under inventoryItem from API 2024-04+), price, compareAtPrice,
     *      barcode, inventoryPolicy, taxable, tracked, requiresShipping.
     *   4. Local DB write inside a transaction. On unique-SKU conflict, the
     *      upstream product is deleted so we don't leave an orphan.
     */
    protected function createProductInShopify(RetailEdgeProduct $product)
    {
        try {
            $this->info("Creating product: {$product->title} (SKU: {$product->sku})");

            // Defensive: a child row that slipped past the parent-only
            // filter must never be created as a standalone product. Mark
            // uploaded so it's not picked up again.
            if (! empty($product->old_key) && $product->old_key !== $product->sku) {
                Log::warning('CreateProductGraphQL: skipping child product that slipped through filter', [
                    'sku' => $product->sku,
                    'old_key' => $product->old_key,
                ]);
                $product->update(['uploaded_to_shopify' => 1]);

                return;
            }

            // If any child SKU is already on Shopify, the parent and its
            // children are effectively already represented. Mark them
            // uploaded and skip — recreating would just produce a
            // duplicate parent product.
            $childSkus = $product->children->pluck('sku')->filter()->all();
            if (! empty($childSkus)) {
                $existingChildSkus = ShopifyProductVariant::whereIn('sku', $childSkus)
                    ->pluck('sku')
                    ->all();

                if (! empty($existingChildSkus)) {
                    Log::info('CreateProductGraphQL: skipping parent — children already on Shopify', [
                        'parent_sku' => $product->sku,
                        'existing_child_skus' => $existingChildSkus,
                    ]);
                    $this->info("Skipping {$product->sku}: children already exist on Shopify");

                    $product->update(['uploaded_to_shopify' => 1]);
                    $product->children()
                        ->whereIn('sku', $existingChildSkus)
                        ->update(['uploaded_to_shopify' => 1]);

                    return;
                }
            }

            if ($this->adoptExistingShopifyProductBySku($product)) {
                return;
            }

            [$price, $compareAtPrice] = $this->resolvePrices($product);

            $productInput = [
                'title' => $product->title,
                'descriptionHtml' => $product->marketing_description ?? '',
                'vendor' => $product->brand?->name ?? '',
                'productType' => $product->s_cat ?? '',
                'tags' => $this->calculateTags($product),
                'status' => 'ACTIVE',
            ];

            $response = $this->graphqlService->mutate(
                ProductMutations::createProduct(),
                ['product' => $productInput]
            );

            if (! isset($response['productCreate']['product'])) {
                $this->markCreateFailed($product, $response['productCreate']['userErrors'] ?? [], 'product');

                return;
            }

            $createdProduct = $response['productCreate']['product'];
            $productGid = $createdProduct['id'];
            $defaultVariant = $createdProduct['variants']['nodes'][0] ?? null;

            if (! $defaultVariant) {
                $this->error("productCreate did not return a default variant for SKU {$product->sku}");
                Log::error("productCreate did not return a default variant for SKU {$product->sku}");
                $this->deleteUpstreamProduct($productGid);
                $product->update(['uploaded_to_shopify' => 2]);

                return;
            }

            $variantInput = [
                'id' => $defaultVariant['id'],
                'price' => (string) $price,
                'barcode' => $product->barcode,
                'inventoryPolicy' => 'DENY',
                'taxable' => true,
                'inventoryItem' => [
                    'sku' => $product->sku,
                    'tracked' => true,
                    'requiresShipping' => true,
                ],
            ];

            if ($compareAtPrice && $compareAtPrice != $price) {
                $variantInput['compareAtPrice'] = (string) $compareAtPrice;
            }

            $variantResponse = $this->graphqlService->mutate(
                ProductMutations::bulkUpdateVariants(),
                [
                    'productId' => $productGid,
                    'variants' => [$variantInput],
                ]
            );

            $updatedVariant = $variantResponse['productVariantsBulkUpdate']['product']['variants']['nodes'][0] ?? null;

            if (! $updatedVariant) {
                $this->markCreateFailed($product, $variantResponse['productVariantsBulkUpdate']['userErrors'] ?? [], 'variant');
                $this->deleteUpstreamProduct($productGid);

                return;
            }

            try {
                $this->saveCreatedProductAndVariant($createdProduct, $updatedVariant);
            } catch (QueryException $e) {
                $this->error("Local DB write failed for SKU {$product->sku}: {$e->getMessage()}. Rolling back upstream product.");
                Log::error("Local DB write failed for SKU {$product->sku}: ".$e->getMessage());
                $this->deleteUpstreamProduct($productGid);
                $product->update(['uploaded_to_shopify' => 2]);

                return;
            }

            $product->update(['uploaded_to_shopify' => 1]);

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
     * Look up an existing Shopify product by SKU and import its IDs into the
     * local database. Returns true when a match is found and adopted.
     * Exceptions propagate intentionally — swallowing a transport error here
     * and falling through to productCreate would defeat the duplicate guard.
     */
    protected function adoptExistingShopifyProductBySku(RetailEdgeProduct $product): bool
    {
        if (! $product->sku) {
            return false;
        }

        $response = $this->graphqlService->query(
            ProductQueries::findVariantBySku(),
            ['query' => "sku:{$product->sku}"]
        );

        $variants = $response['productVariants']['nodes'] ?? [];

        $match = collect($variants)->first(
            fn ($variant) => isset($variant['sku']) && $variant['sku'] === $product->sku
        );

        if (! $match) {
            return false;
        }

        $this->info("Found existing Shopify product for SKU {$product->sku}; adopting locally.");
        Log::info("Adopting pre-existing Shopify product for SKU {$product->sku}");

        $productData = $match['product'];
        $variantData = $match;
        unset($variantData['product']);

        $this->saveCreatedProductAndVariant($productData, $variantData);

        $product->update(['uploaded_to_shopify' => 1]);

        foreach ($product->children as $child) {
            $child->update(['uploaded_to_shopify' => 1]);
        }

        return true;
    }

    /**
     * Persist product + variant in one transaction. The variant's
     * inventory_item_id is written in the same row insert so we never have a
     * window where the row exists without it.
     */
    protected function saveCreatedProductAndVariant(array $productData, array $variantData): void
    {
        DB::transaction(function () use ($productData, $variantData) {
            $productId = $this->graphqlService->extractRestId($productData['id']);
            $variantId = $this->graphqlService->extractRestId($variantData['id']);

            $sku = $variantData['inventoryItem']['sku'] ?? $variantData['sku'] ?? null;
            $inventoryItemId = isset($variantData['inventoryItem']['id'])
                ? $this->graphqlService->extractRestId($variantData['inventoryItem']['id'])
                : null;

            $shopifyProduct = ShopifyProduct::updateOrCreate(
                ['product_id' => $productId],
                [
                    'title' => $productData['title'],
                    'vendor' => $productData['vendor'] ?? null,
                    'product_type' => $productData['productType'] ?? null,
                    'handle' => $productData['handle'],
                    'tags' => implode(',', $productData['tags'] ?? []),
                    'status' => strtolower($productData['status']),
                ]
            );

            ShopifyProductVariant::create([
                'shopify_product_id' => $shopifyProduct->id,
                'sku' => $sku,
                'variant_id' => $variantId,
                'product_id' => $productId,
                'title' => $variantData['title'] ?? 'Default Title',
                'price' => $variantData['price'] ?? 0,
                'compare_at_price' => $variantData['compareAtPrice'] ?: 0,
                'position' => 1,
                'inventory_policy' => strtolower($variantData['inventoryPolicy'] ?? 'deny'),
                'fulfillment_service' => 'manual',
                'inventory_management' => 'shopify',
                'option1' => 'Default Title',
                'option2' => null,
                'option3' => null,
                'taxable' => $variantData['taxable'] ?? true,
                'barcode' => $variantData['barcode'] ?? null,
                'grams' => 0,
                'weight' => 0,
                'inventory_item_id' => $inventoryItemId,
                'inventory_quantity' => 0,
                'old_inventory_quantity' => 0,
                'requires_shipping' => $variantData['inventoryItem']['requiresShipping'] ?? true,
                'price_requires_update' => 1,
                'inventory_requires_update' => 1,
                'images_requires_update' => 1,
            ]);

            if ($sku) {
                RetailEdgeProduct::where('sku', $sku)->update(['uploaded_to_shopify' => 1]);
            }
        });
    }

    /**
     * Resolve (price, compareAtPrice) from the two retail-price columns.
     * The lower price is the regular price; if the higher is meaningfully
     * greater, it's the compare-at price.
     */
    private function resolvePrices(RetailEdgeProduct $product): array
    {
        $candidates = array_filter(
            array_map('floatval', [$product->retail_price1, $product->retail_price2]),
            fn ($p) => $p > 0
        );

        if (empty($candidates)) {
            return [0, null];
        }

        $price = min($candidates);
        $compareAtPrice = max($candidates);

        return [$price, $price == $compareAtPrice ? null : $compareAtPrice];
    }

    private function markCreateFailed(RetailEdgeProduct $product, array $userErrors, string $stage): void
    {
        $messages = array_map(fn ($e) => $e['message'] ?? 'Unknown error', $userErrors);
        $errorMessage = ! empty($messages) ? implode(', ', $messages) : "Unknown error creating $stage";

        $this->error("Failed to create $stage for SKU {$product->sku}: {$errorMessage}");
        Log::error("Error creating $stage for {$product->sku}: {$errorMessage}");

        $product->update(['uploaded_to_shopify' => 2]);

        foreach ($product->children as $child) {
            $child->update(['uploaded_to_shopify' => 2]);
        }
    }

    /**
     * Best-effort delete of a Shopify product when local persistence fails.
     * Logged on failure but never thrown — the local create has already
     * decided this attempt is over.
     */
    private function deleteUpstreamProduct(string $productGid): void
    {
        try {
            $this->graphqlService->mutate(
                ProductMutations::deleteProduct(),
                ['input' => ['id' => $productGid]]
            );
            Log::info("Rolled back upstream product $productGid after local failure.");
        } catch (\Exception $e) {
            Log::error("Failed to roll back upstream product $productGid: ".$e->getMessage());
        }
    }

    private function calculateTags(RetailEdgeProduct $product): array
    {
        $types = [
            's_web_menu' => 'S.WebMenu',
            's_metal_type' => 'S.Metal Type',
            's_stone_type' => 'S.Stone Type',
            's_cat' => 'S.Cat',
            's_sub_cat' => 'S.Sub Cat',
        ];

        $tags = [];

        foreach ($types as $property => $prefix) {
            $value = $product->{$property} ?? '';
            if ($value === '' || $value === 'N/A') {
                continue;
            }

            foreach (explode(',', $value) as $part) {
                $tags[] = $prefix.'_'.trim($part);
            }
        }

        return $tags;
    }
}
