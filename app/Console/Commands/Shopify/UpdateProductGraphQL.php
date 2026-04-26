<?php

namespace App\Console\Commands\Shopify;

use App\Models\EWeb\RetailEdgeProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\GraphQL\ProductMutations;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateProductGraphQL extends Command
{
    protected $signature = 'shopify:update-product-graphql';

    protected $description = 'Update Shopify product tags using GraphQL API';

    protected ShopifyGraphQLService $graphqlService;

    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdateProductGraphQL';

        $job = (new SyncJobService)->claim($jobType, $marketplace);

        if (! $job) {
            Log::info("$marketplace $jobType is already running.");

            return;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $this->graphqlService = new ShopifyGraphQLService;

            $variants = ShopifyProductVariant::withWhereHas('retailEdgeProduct')
                ->with('product')
                ->where('requires_update', 1)
                ->select('id', 'shopify_product_id', 'product_id', 'sku')
                ->get();

            foreach ($variants as $variant) {
                $this->updateTagsForVariant($variant);
                usleep(1500000);
            }

            $job->update(['status' => 0, 'message' => null]);
            Log::info("$marketplace $jobType finished!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            report($e);
            $this->error($e->getMessage());
        }
    }

    protected function updateTagsForVariant(ShopifyProductVariant $variant): void
    {
        if (! $variant->product || ! $variant->product->product_id) {
            $variant->update(['requires_update' => 2]);

            return;
        }

        $tags = $this->calculateTags($variant->retailEdgeProduct, $variant->product->tags);
        $tagsString = implode(',', $tags);

        try {
            $productGid = $this->graphqlService->formatGraphQLId('Product', $variant->product->product_id);

            $response = $this->graphqlService->mutate(
                ProductMutations::updateProduct(),
                [
                    'product' => [
                        'id' => $productGid,
                        'tags' => $tags,
                    ],
                ]
            );

            if (isset($response['productUpdate']['product'])) {
                $variant->update(['requires_update' => 0]);
                $variant->product->update(['tags' => $tagsString]);
                $this->info("Tags updated for SKU {$variant->sku}");
            } else {
                $variant->update(['requires_update' => 2]);
                Log::warning("Tag update returned no product for SKU {$variant->sku}");
            }
        } catch (\Exception $e) {
            $variant->update(['requires_update' => 2]);
            Log::warning("Tag update failed for {$variant->sku}: ".$e->getMessage());
            $this->error("Tag update failed for {$variant->sku}: ".$e->getMessage());
        }
    }

    private function calculateTags(RetailEdgeProduct $product, string|array|null $existingTags = null): array
    {
        $tags = $this->normalizeExistingTags($existingTags);

        $types = [
            's_web_menu' => 'S.WebMenu',
            's_metal_type' => 'S.Metal Type',
            's_stone_type' => 'S.Stone Type',
            's_cat' => 'S.Cat',
            's_sub_cat' => 'S.Sub Cat',
        ];

        foreach ($types as $propertyName => $tagPrefix) {
            $propertyValue = $product->{$propertyName} ?? '';
            if ($propertyValue !== '' && $propertyValue !== 'N/A') {
                foreach (explode(',', $propertyValue) as $tagValue) {
                    $tags[] = trim($tagPrefix).'_'.trim($tagValue);
                }
            }
        }

        return array_values(array_unique($tags));
    }

    private function normalizeExistingTags(string|array|null $existingTags): array
    {
        if (empty($existingTags)) {
            return [];
        }

        $tags = is_array($existingTags) ? $existingTags : explode(',', $existingTags);

        return array_map('trim', $tags);
    }
}
