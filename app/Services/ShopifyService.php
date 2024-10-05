<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Shopify\Rest\Admin2024_07\Image;
use App\Models\Shopify\ShopifyProductVariant;
use App\Models\Shopify\ShopifyInventoryLevel;
use App\Models\Shopify\ShopifyProduct;
use App\Models\EWeb\RetailEdgeProduct;

class ShopifyService extends ShopifyConnectionService
{
    public function saveProductToDb($productData)
    {
        try {
            DB::beginTransaction();
            foreach ($productData['variants'] as $variant) {
                if ($shopifyProductVariant = ShopifyProductVariant::where('variant_id', $variant['id'])->first()) {

                    $shopifyProduct = ShopifyProduct::updateOrCreate(
                        [
                            'product_id' => $productData['id']
                        ],
                        [
                            'title' => $productData['title'],
                            'vendor' => $productData['vendor'],
                            'product_type' => $productData['product_type'],
                            'handle' => $productData['handle'],
                            'tags' => $productData['tags'],
                            'status' => $productData['status'],
                        ]
                    );

                    $shopifyProductVariant->update(
                        [
                            'product_id' => $variant['product_id'],
                            'title' => $variant['title'],
                            'price' => $variant['price'],
                            'position' => $variant['position'],
                            'inventory_policy' => $variant['inventory_policy'],
                            'fulfillment_service' => $variant['fulfillment_service'],
                            'inventory_management' => $variant['inventory_management'],
                            'option1' => $variant['option1'],
                            'option2' => $variant['option2'],
                            'option3' => $variant['option3'],
                            'taxable' => $variant['taxable'],
                            'barcode' => $variant['barcode'],
                            'grams' => $variant['grams'],
                            'weight' => $variant['weight'],
                            'inventory_item_id' => $variant['inventory_item_id'],
                            'inventory_quantity' => $variant['inventory_quantity'],
                            'old_inventory_quantity' => $variant['old_inventory_quantity'],
                            'requires_shipping' => $variant['requires_shipping'],
                        ]
                    );
                } else {
                    $shopifyProduct = ShopifyProduct::updateOrCreate(
                        [
                            'product_id' => $productData['id']
                        ],
                        [
                            'title' => $productData['title'],
                            'vendor' => $productData['vendor'],
                            'product_type' => $productData['product_type'],
                            'handle' => $productData['handle'],
                            'tags' => $productData['tags'],
                            'status' => $productData['status'],
                        ]
                    );

                    $shopifyProductVariant = ShopifyProductVariant::create(
                        [
                            'shopify_product_id' => $shopifyProduct->id,
                            'sku' => $variant['sku'],
                            'variant_id' => $variant['id'],
                            'product_id' => $shopifyProduct->product_id,
                            'title' => $variant['title'],
                            'price' => $variant['price'],
                            'compare_at_price' => $variant['compare_at_price'] ? $variant['compare_at_price'] : 0,
                            'position' => $variant['position'],
                            'inventory_policy' => $variant['inventory_policy'],
                            'fulfillment_service' => $variant['fulfillment_service'],
                            'inventory_management' => $variant['inventory_management'],
                            'option1' => $variant['option1'],
                            'option2' => $variant['option2'],
                            'option3' => $variant['option3'],
                            'taxable' => $variant['taxable'],
                            'barcode' => $variant['barcode'],
                            'grams' => $variant['grams'],
                            'weight' => $variant['weight'],
                            'inventory_item_id' => $variant['inventory_item_id'],
                            'inventory_quantity' => $variant['inventory_quantity'],
                            'old_inventory_quantity' => isset($variant['old_inventory_quantity']) ? $variant['old_inventory_quantity'] : 0,
                            'requires_shipping' => $variant['requires_shipping'],
                            'price_requires_update' => 1,
                            'inventory_requires_update' => 1,
                            'images_requires_update' => 1,
                        ]
                    );
                }

                if ($shopifyProductVariant) {
                    RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)->update(['uploaded_to_shopify' => 1]);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function saveProductToDbNewVersion($productData)
    {
        try {
            DB::beginTransaction();
            foreach ($productData['variants'] as $variant) {
                if ($shopifyProductVariant = ShopifyProductVariant::where('variant_id', $variant['id'])->first()) {
                    $shopifyProductVariant->update(
                        [
                            'product_id' => $variant['product_id'],
                            'title' => $variant['title'],
                            'price' => $variant['price'],
                            'position' => $variant['position'],
                            'inventory_policy' => $variant['inventory_policy'],
                            'fulfillment_service' => $variant['fulfillment_service'],
                            'inventory_management' => $variant['inventory_management'],
                            'option1' => $variant['option1'],
                            'option2' => $variant['option2'],
                            'option3' => $variant['option3'],
                            'taxable' => $variant['taxable'],
                            'barcode' => $variant['barcode'],
                            'grams' => $variant['grams'],
                            'weight' => $variant['weight'],
                            'inventory_item_id' => $variant['inventory_item_id'],
                            'inventory_quantity' => $variant['inventory_quantity'],
                            'old_inventory_quantity' => $variant['old_inventory_quantity'],
                            'requires_shipping' => $variant['requires_shipping'],
                        ]
                    );
                } else {
                    $product = RetailEdgeProduct::where('sku', $variant['sku'])->with('parent')->first();
                    $shopifyProduct = ShopifyProduct::firstOrCreate(
                        [
                            'sku' => $product->parent->sku,
                        ],
                        [
                            'product_id' => $productData['id'],
                            'title' => $productData['title'],
                            'vendor' => $productData['vendor'],
                            'product_type' => $productData['product_type'],
                            'handle' => $productData['handle'],
                            'tags' => $productData['tags'],
                            'status' => $productData['status'],
                        ]
                    );

                    $shopifyProductVariant = ShopifyProductVariant::create(
                        [
                            'shopify_product_id' => $shopifyProduct->id,
                            'sku' => $variant['sku'],
                            'variant_id' => $variant['id'],
                            'product_id' => $shopifyProduct->product_id,
                            'title' => $variant['title'],
                            'price' => $variant['price'],
                            'compare_at_price' => $variant['compare_at_price'] ? $variant['compare_at_price'] : 0,
                            'position' => $variant['position'],
                            'inventory_policy' => $variant['inventory_policy'],
                            'fulfillment_service' => $variant['fulfillment_service'],
                            'inventory_management' => $variant['inventory_management'],
                            'option1' => $variant['option1'],
                            'option2' => $variant['option2'],
                            'option3' => $variant['option3'],
                            'taxable' => $variant['taxable'],
                            'barcode' => $variant['barcode'],
                            'grams' => $variant['grams'],
                            'weight' => $variant['weight'],
                            'inventory_item_id' => $variant['inventory_item_id'],
                            'inventory_quantity' => $variant['inventory_quantity'],
                            'old_inventory_quantity' => isset($variant['old_inventory_quantity']) ? $variant['old_inventory_quantity'] : 0,
                            'requires_shipping' => $variant['requires_shipping'],
                        ]
                    );
                }

                if ($shopifyProductVariant) {
                    RetailEdgeProduct::where('sku', $shopifyProductVariant->sku)->update(['uploaded_to_shopify' => 1]);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function saveInventoryLevelToDb($inventoryLevelData)
    {
        try {
            return ShopifyInventoryLevel::updateOrCreate(
                [
                    'location_id' => $inventoryLevelData['location_id'],
                    'inventory_item_id' => $inventoryLevelData['inventory_item_id'],

                ],
                [
                    'available' => $inventoryLevelData['available'],
                    'inventory_updated_at' => Carbon::parse($inventoryLevelData['updated_at']),
                ]
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function deleteImagesByProductId(string $productId)
    {
        $session = $this->getSession();
        $images = Image::all(
            $session,
            ["product_id" => $productId]
        );
        foreach ($images as $image) {
            Image::delete(
                $session,
                $image->id,
                ["product_id" => $productId],
            );
        }

        return true;
    }

    public function uploadImages(ShopifyProductVariant $variant, string $imageContent)
    {
        try {
            $session = $this->getSession();

            try {
                $image = new Image($session);
                $image->product_id = $variant->product_id;
                $image->attachment = $imageContent;
                $image->variant_ids = [
                    $variant->variant_id
                ];

                $image->save(
                    true,
                );
                $variant->update(['images_requires_update' => 0]);
                return 'ok';
            } catch (\Exception $e) {
                Log::debug("There was an error while uploading the images for {$variant->sku}. Error message : {$e->getMessage()}");
                report($e);
                $variant->update(['images_requires_update' => 2]);
            }
        } catch (\Exception $e) {
            throw ($e);
        }
    }
}
