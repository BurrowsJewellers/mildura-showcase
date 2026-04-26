<?php

namespace App\Console\Commands\Shopify;

use App\Models\Shopify\ShopifyProductVariant;
use App\Services\GraphQL\ProductMutations;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UploadImagesGraphQL extends Command
{
    protected $signature = 'shopify:upload-images-graphql';

    protected $description = 'Upload variant images to Shopify using GraphQL API';

    protected ShopifyGraphQLService $graphqlService;

    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUploadImagesGraphQL';

        $job = (new SyncJobService)->claim($jobType, $marketplace);

        if (! $job) {
            Log::info("$marketplace $jobType is already running.");

            return;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $this->graphqlService = new ShopifyGraphQLService;

            $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
            $this->info("Variants needing image upload: {$count}");

            while ($count > 0) {
                $variant = ShopifyProductVariant::with(['images', 'product'])
                    ->where('images_requires_update', 1)
                    ->first();

                if (! $variant) {
                    break;
                }

                $this->uploadVariantImages($variant);
                usleep(1500000);

                $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
                $this->info("Remaining: {$count}");
            }

            $job->update(['status' => 0, 'message' => null]);
            Log::info("$marketplace $jobType finished!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            report($e);
            $this->error($e->getMessage());
        }
    }

    protected function uploadVariantImages(ShopifyProductVariant $variant): void
    {
        if (! $variant->product || ! $variant->product->product_id) {
            $variant->update(['images_requires_update' => 2]);

            return;
        }

        $images = $variant->images ?? collect();

        if ($images->isEmpty()) {
            $variant->update(['images_requires_update' => 0]);

            return;
        }

        $productGid = $this->graphqlService->formatGraphQLId('Product', $variant->product->product_id);

        $mediaInput = $images
            ->filter(fn ($image) => ! empty($image->url))
            ->map(fn ($image) => [
                'mediaContentType' => 'IMAGE',
                'originalSource' => $image->url,
                'alt' => $variant->sku,
            ])
            ->values()
            ->all();

        if (empty($mediaInput)) {
            $variant->update(['images_requires_update' => 0]);

            return;
        }

        try {
            $response = $this->graphqlService->mutate(
                ProductMutations::createProductMedia(),
                [
                    'productId' => $productGid,
                    'media' => $mediaInput,
                ]
            );

            $createdMedia = $response['productCreateMedia']['media'] ?? [];

            if (! empty($createdMedia)) {
                $mediaIds = array_values(array_filter(array_map(
                    fn ($media) => $media['id'] ?? null,
                    $createdMedia
                )));

                if (! empty($mediaIds)) {
                    $variantGid = $this->graphqlService->formatGraphQLId('ProductVariant', $variant->variant_id);

                    $this->graphqlService->mutate(
                        ProductMutations::updateVariantMedia(),
                        [
                            'productId' => $productGid,
                            'variantMedia' => [
                                [
                                    'variantId' => $variantGid,
                                    'mediaIds' => $mediaIds,
                                ],
                            ],
                        ]
                    );
                }
            }

            $variant->update(['images_requires_update' => 0]);
            $this->info("Images uploaded for SKU {$variant->sku}");
        } catch (\Exception $e) {
            $variant->update(['images_requires_update' => 2]);
            Log::warning("Image upload failed for {$variant->sku}: ".$e->getMessage());
            $this->error("Image upload failed for {$variant->sku}: ".$e->getMessage());
        }
    }
}
