<?php

namespace App\Traits;

use App\Models\EWeb\RetailEdgeProduct;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use Illuminate\Support\Facades\Log;

/**
 * Cascading-cleanup helpers for Shopify mirror rows. The conditional
 * uploaded_to_shopify reset is what stops the recreation loop: if any
 * other Shopify variant row still references the SKU we leave the flag
 * alone, because the SKU is still represented on Shopify under another
 * product.
 */
trait ShopifyCleanupTrait
{
    protected function cleanupStaleVariant(ShopifyProductVariant $variant, string $context = 'ShopifyCleanup'): bool
    {
        try {
            $sku = $variant->sku ?: '[EMPTY SKU]';
            $shopifyProductId = $variant->shopify_product_id;

            $variant->forceDelete();

            if ($shopifyProductId) {
                $remainingVariants = ShopifyProductVariant::where('shopify_product_id', $shopifyProductId)->count();
                if ($remainingVariants === 0) {
                    ShopifyProduct::where('id', $shopifyProductId)->forceDelete();
                }
            }

            $remainingSkuVariants = ShopifyProductVariant::where('sku', $sku)->count();
            if ($remainingSkuVariants === 0) {
                RetailEdgeProduct::where('sku', $sku)->update(['uploaded_to_shopify' => 0]);
            }

            Log::info("{$context}: cleaned stale variant {$sku}");

            return true;
        } catch (\Throwable $e) {
            Log::error("{$context}: failed to clean stale variant: ".$e->getMessage());

            return false;
        }
    }

    protected function cleanupStaleProduct(object $product, string $context = 'ShopifyCleanup'): bool
    {
        try {
            if ($product instanceof ShopifyProduct) {
                $shopifyProduct = $product;
                $title = $product->title ?? 'Unknown';
            } else {
                $shopifyProduct = ShopifyProduct::find($product->pid ?? $product->id ?? null);
                $title = $product->title ?? 'Unknown';

                if (! $shopifyProduct) {
                    Log::warning("{$context}: could not find ShopifyProduct for cleanup");

                    return false;
                }
            }

            $variantSkus = $shopifyProduct->variants()->pluck('sku')->filter()->unique()->all();

            $shopifyProduct->variants()->forceDelete();
            $shopifyProduct->forceDelete();

            foreach ($variantSkus as $sku) {
                $remaining = ShopifyProductVariant::where('sku', $sku)->count();
                if ($remaining === 0) {
                    RetailEdgeProduct::where('sku', $sku)->update(['uploaded_to_shopify' => 0]);
                }
            }

            Log::info("{$context}: cleaned stale product {$title}");

            return true;
        } catch (\Throwable $e) {
            $title = $product->title ?? 'unknown';
            Log::error("{$context}: failed to clean stale product {$title}: ".$e->getMessage());

            return false;
        }
    }
}
