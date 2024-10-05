<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Rest;
use App\Services\ShopifyService;
use App\Services\SyncJobService;
use App\Models\Shopify\ShopifyInventoryLevel;
use App\Models\Shopify\ShopifyLocation;
use App\Models\Shopify\ShopifyProduct;

class GetProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyGetProducts';

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
        $jobType = 'shopifyGetProducts';

        $job = (new SyncJobService())->getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                /**
                 * Get Shopify locations
                 */

                $this->getLocations();

                /**
                 * Get Shopify products
                 */

                $this->getProducts();

                /**
                 * Get Shopify inventory levels
                 */

                $this->getInventoryLevels();

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

    public function getLocations()
    {
        try {
            $session = (new ShopifyService)->getSession();
            $client = new Rest($session->getShop(), $session->getAccessToken());

            $response = $client->get(path: 'locations');

            $body = $response->getDecodedBody();

            if (!empty($body) && isset($body['locations']) && count($body['locations']) > 0) {
                foreach ($body['locations'] as $locationData) {
                    try {
                        ShopifyLocation::updateOrCreate(
                            [
                                'location_id' => $locationData['id'],
                            ],
                            [
                                'name' => $locationData['name'],
                                'address1' => $locationData['address1'],
                                'address2' => $locationData['address2'],
                                'city' => $locationData['city'],
                                'zip' => $locationData['zip'],
                                'province' => $locationData['province'],
                                'country' => $locationData['country'],
                                'phone' => $locationData['phone'],
                                'country_code' => $locationData['country_code'],
                                'country_name' => $locationData['country_name'],
                                'province_code' => $locationData['province_code'],
                                'active' => $locationData['active'],
                            ]
                        );
                    } catch (\Exception $e) {
                        $this->error($e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getProducts()
    {
        try {
            $nextPage = true;
            $getNextPageQuery = [];
            $productIds = [];

            while ($nextPage) {
                $session = (new ShopifyService)->getSession();
                $client = new Rest($session->getShop(), $session->getAccessToken());

                /** @var RestResponse */
                $response = $client->get(path: 'products', query: $getNextPageQuery);

                $body = $response->getDecodedBody();

                if (!empty($body) && isset($body['products']) && count($body['products']) > 0) {
                    foreach ($body['products'] as $productData) {
                        try {
                            $this->info('product id : ' . $productData['id']);
                            $productIds[] = $productData['id'];
                            // if ($productData['status'] !== 'archived') {
                            (new ShopifyService)->saveProductToDb($productData);
                            // }
                        } catch (\Exception $e) {
                            $this->error($e->getMessage());
                        }
                    }
                }

                $serializedPageInfo = serialize($response->getPageInfo());

                /** @var \Shopify\Clients\PageInfo */
                $pageInfo = unserialize($serializedPageInfo);

                if ($pageInfo && $pageInfo->hasNextPage()) {
                    $getNextPageQuery = $pageInfo->getNextPageQuery();
                    $this->info('getNextPageQuery');
                    sleep(1);
                } else {
                    $nextPage = false;
                }
            }

            $this->deleteShopifyProductFromDb(array_values($productIds));
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getInventoryLevels()
    {
        try {
            $nextPage = true;
            $getNextPageQuery = [];

            $location = ShopifyLocation::first();
            while ($nextPage) {
                $this->info('While loop start');
                $session = (new ShopifyService)->getSession();
                $client = new Rest($session->getShop(), $session->getAccessToken());

                if (empty($getNextPageQuery)) {
                    $params = ['location_ids' => $location->location_id, 'limit' => 250];
                } else {
                    $params = $getNextPageQuery;
                }

                /** @var RestResponse */
                $response = $client->get(path: 'inventory_levels', query: $params);

                $body = $response->getDecodedBody();

                if (!empty($body) && isset($body['inventory_levels']) && count($body['inventory_levels']) > 0) {
                    foreach ($body['inventory_levels'] as $inventoryLevelData) {
                        try {
                            $this->info('product id : ' . $inventoryLevelData['inventory_item_id']);
                            (new ShopifyService)->saveInventoryLevelToDb($inventoryLevelData);
                        } catch (\Exception $e) {
                            $this->error($e->getMessage());
                        }
                    }
                }

                $serializedPageInfo = serialize($response->getPageInfo());

                /** @var \Shopify\Clients\PageInfo */
                $pageInfo = unserialize($serializedPageInfo);

                if ($pageInfo) {
                    if ($pageInfo->hasNextPage()) {
                        $getNextPageQuery = $pageInfo->getNextPageQuery();
                        $this->info('getNextPageQuery');
                        sleep(1);
                    } else {
                        $nextPage = false;
                    }
                } else {
                    $this->info('pageInfo is null');
                    $nextPage = false;
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function deleteShopifyProductFromDb($productIds)
    {
        // DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        $shopifyProducts = ShopifyProduct::whereNotIn('product_id', $productIds)->with('variants')->get();

        foreach ($shopifyProducts as $shopifyProduct) {
            try {
                $shopifyProduct->forceDelete();
                $message = 'Product deleted successfully: ' . $shopifyProduct->product_id;
                $this->info($message);
                Log::debug($message);
            } catch (\Exception $e) {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
                $message = 'Error while deleting shopify product. ' . $e->getMessage();
                $this->info($message);
                Log::debug($message);
            }
        }
        // DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
    }

    public function deleteShopifyProductFromDbBackup($productIds)
    {
        // DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        $shopifyProducts = ShopifyProduct::whereNotIn('product_id', $productIds)->with('variants')->get();

        foreach ($shopifyProducts as $shopifyProduct) {
            try {
                foreach ($shopifyProduct->variants as $variant) {
                    ShopifyInventoryLevel::where('inventory_item_id', $variant->inventory_item_id)->delete();
                    $variant->delete();
                }
                $message = 'Product deleted successfully: ' . $shopifyProduct->product_id;
                $shopifyProduct->forceDelete();
                $this->info($message);
                Log::debug($message);
            } catch (\Exception $e) {
                // DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
                $message = 'Error while deleting shopify product. ' . $e->getMessage();
                $this->info($message);
                Log::debug($message);
            }
        }
        // DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
    }
}
