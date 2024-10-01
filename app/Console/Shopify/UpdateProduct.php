<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\Brand;
use App\Models\RetailEdgeProduct;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Shopify\Rest\Admin2024_01\Product;

class UpdateProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUpdateProduct';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'The code to update the Shopify product tags';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyUpdateProduct';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $session = (new ShopifyService)->getSession();
                $brands = Brand::all();

                $brandsArray = [];

                foreach ($brands as $brand) {
                    $brandsArray[$brand->brand_id]['id'] = $brand->id;
                    $brandsArray[$brand->brand_id]['name'] = $brand->name;
                }

                $variants = ShopifyProductVariant::withWhereHas('retailEdgeProduct')->with('product')->where('requires_update', 1)->select('id', 'shopify_product_id', 'product_id', 'sku')->get();

                foreach ($variants as $variant) {
                    $this->info('Updating: ' . $variant->sku);
                    $productTags = $this->calculateTags($variant->retailEdgeProduct, $variant->product->tags);

                    if ($variant->retailEdgeProduct->brand?->name == 'Pandora') {
                        $productTags[] = 'Pandora';
                    }

                    $tags = implode(",", $productTags);

                    try {
                        $product = new Product($session);
                        $product->id = $variant->product_id;
                        $product->tags = $tags;

                        if ($variant->retailEdgeProduct->brand?->name == 'Pandora') {
                            $product->template_suffix = 'no-buy';
                            $product->vendor = $variant->retailEdgeProduct->brand?->name;
                        }

                        $product->save(true);

                        $variant->update(['requires_update' => 0]);
                        $variant->product->update(['tags' => $tags]);
                    } catch (\Exception $e) {
                        report($e);
                        $this->error($e->getMessage());
                    }
                    usleep(1500000);
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

    private function calculateTags(RetailEdgeProduct $product, string|array $existingTags = null): array
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
            $this->addProductPropertyTags($product, $propertyName, $tagPrefix, $tags);
        }

        return array_unique($tags);
    }

    private function normalizeExistingTags(string|array|null $existingTags): array
    {
        if (empty($existingTags)) {
            return [];
        }

        $tags = is_array($existingTags) ? $existingTags : explode(",", $existingTags);
        return array_map('trim', $tags);
    }

    private function addProductPropertyTags(RetailEdgeProduct $product, string $propertyName, string $tagPrefix, array &$tags): void
    {
        $propertyValue = $product->{$propertyName} ?? '';
        if ($propertyValue !== '' && $propertyValue !== 'N/A') {
            foreach (explode(",", $propertyValue) as $tagValue) {
                $tags[] = trim($tagPrefix) . "_" . trim($tagValue);
            }
        }
    }
}
