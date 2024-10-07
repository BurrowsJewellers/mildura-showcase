<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Rest;
use App\Services\ShopifyService;
use App\Services\SyncJobService;
use App\Models\EWeb\Brand;
use App\Models\EWeb\RetailEdgeProduct;

class CreateProductOld extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyCreateProductOld';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyCreateProductOld';

        $job = (new SyncJobService())->getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                // $job->update(['status' => 1]);

                $pendingProducts = DB::select("SELECT rep.id, rep.sku
                    FROM retail_edge_products rep
                    LEFT JOIN shopify_product_variants spv ON rep.sku = spv.sku
                    WHERE spv.id IS NULL;
                ");

                $pendingProductIds = [];

                foreach ($pendingProducts as $p) {
                    $pendingProductIds[] = $p->id;
                }

                $session = (new ShopifyService)->getSession();

                $brands = Brand::all();

                $brandsArray = [];

                foreach ($brands as $brand) {
                    $brandsArray[$brand->brand_id]['id'] = $brand->id;
                    $brandsArray[$brand->brand_id]['name'] = $brand->name;
                }

                $countQuery = RetailEdgeProduct::whereIn('id', $pendingProductIds)->whereHas('children', function ($children) {
                    $children->where('uploaded_to_shopify', 0);
                })->where('quantity', '>', 0);

                $count = $countQuery->count();

                while ($count) {
                    $this->info('Count: ' . $count);
                    $product = RetailEdgeProduct::withWhereHas('children', function ($children) {
                        $children->where('uploaded_to_shopify', 0);
                    })->with(['brand'])->where('quantity', '>', 0)->first();

                    if ($product) {
                        $this->info('======================================');
                        $variants = [];
                        if ($product->children->count()) {
                            foreach ($product->children as $child) {
                                $variant = [];
                                $variant['sku'] = $child->sku;

                                $retailPrices = [$child->retail_price1, $child->retail_price2];

                                // Convert all prices to float and filter out non-positive values
                                $prices = array_filter(array_map('floatval', $retailPrices), function ($price) {
                                    return $price > 0;
                                });

                                // Set default values
                                $price = 0;
                                $compareAtPrice = 0;

                                // Find the lower price and higher compare_at_price
                                if (!empty($prices)) {
                                    $price = min($prices);
                                    $compareAtPrice = max($prices);
                                }

                                $variant['price'] = $price;
                                $variant['barcode'] = $child->barcode;
                                $variant['compare_at_price'] = ($price == $compareAtPrice) ? 0 : $compareAtPrice;
                                $variant['inventory_management'] = 'shopify';
                                $variants[] = $variant;
                            }
                        }

                        $mktDescription = $product->marketing_description;

                        $productData['product'] = [
                            'title' => $product->title,
                            'body_html' => $mktDescription,
                            'variants' => $variants,
                            'product_type' => $product->s_cat,
                        ];

                        $productData['product']['vendor'] = $product->brand?->name;

                        $productTags = $this->calculateTags($product);
                        $productData['product']['tags'] = implode(",", $productTags);

                        $data = json_encode($productData);

                        $this->info($data);
                        try {
                            $client = new Rest($session->getShop(), $session->getAccessToken());

                            /** @var RestResponse */
                            $response = $client->post(path: 'products', body: $data);
                            $body = $response->getDecodedBody();

                            if (isset($body['product'])) {
                                (new ShopifyService)->saveProductToDb($body['product']);
                                $this->info($body['product']['title'] . ' - saved to database');
                                Log::debug('Shopify product ' . $product->sku . ' created successfully!');

                                foreach ($product->children as $child) {
                                    $child->update(['uploaded_to_shopify' => 1]);
                                }
                            } else {
                                foreach ($product->children as $child) {
                                    $child->update(['uploaded_to_shopify' => 2]);
                                }

                                $message = 'Error while creating product. Sku :' . $product->sku . ', title: '  . $product->title;
                                Log::debug($message);
                                Log::debug($data);
                                Log::debug($body);
                                $this->info($message);
                            }
                        } catch (\Exception $e) {
                            report($e);
                        }
                        usleep(1500000);
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
                    foreach (explode(",", $propValue) as $tempTag) {
                        $tags[] = $value . "_" . trim($tempTag);
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
