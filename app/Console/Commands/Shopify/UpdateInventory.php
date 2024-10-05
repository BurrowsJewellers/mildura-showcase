<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_07\InventoryLevel;
use Shopify\Rest\Admin2024_07\Product;
use App\Services\ShopifyService;
use App\Services\SyncJobService;
use App\Models\Shopify\ShopifyLocation;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;

class UpdateInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUpdateInventory';

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
        $jobType = 'shopifyUpdateInventory';

        $job = (new SyncJobService())->getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $location = ShopifyLocation::first();
                $session = (new ShopifyService)->getSession();

                $count = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();
                $this->info("Remaining {$count}");

                while ($count) {
                    $variant = ShopifyProductVariant::with(['retailEdgeProduct', 'product'])->whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->first();

                    if ($variant) {
                        try {
                            $inventoryLevel = new InventoryLevel($session);
                            $inventoryLevel->set(
                                [], // Params
                                [
                                    'location_id' => $location->location_id,
                                    'inventory_item_id' => $variant->inventory_item_id,
                                    'available' => $variant->retailEdgeProduct->quantity
                                ],
                            );

                            $variant->update(['inventory_quantity' => $variant->retailEdgeProduct->quantity, 'inventory_requires_update' => 0]);
                            $this->info("Inventory updated for sku {$variant->sku}, variant id {$variant->variant_id}");

                            if ($variant->retailEdgeProduct->quantity > 0 && $variant->product->status == 'archived') {
                                try {
                                    $status = 'active';
                                    $product = new Product($session);
                                    $product->id = $variant->product->product_id;
                                    $product->status = $status;
                                    $product->save(
                                        true,
                                    );

                                    ShopifyProduct::where('id', $variant->product->pid)->update(['status' => $status]);

                                    $msg = $variant->product->title . ' marked as ' . $status;
                                    $this->info($msg);
                                    Log::debug($msg);
                                } catch (\Exception $e) {
                                    $msg = "An error occurred while updating the Shopify product status from archived to active. Title: {$variant->product->title}";
                                    $this->info($msg);
                                    Log::debug($msg);
                                }
                            }
                        } catch (\Exception $e) {
                            $variant->update(['inventory_requires_update' => 2]);
                            Log::debug("There was an error while updating the inventory for {$variant->sku}. Error message : {$e->getMessage()}");
                        }
                        usleep(1500000);
                    }

                    $count = ShopifyProductVariant::whereNotNull('inventory_item_id')->where('inventory_requires_update', 1)->count();
                    $this->info("Remaining {$count}");
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
}
