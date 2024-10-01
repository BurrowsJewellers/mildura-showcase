<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyProductVariant;
use App\Services\ShopifyService;
use Shopify\Rest\Admin2024_01\Variant;

class UpdatePrice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUpdatePrice';

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
        $jobType = 'shopifyUpdatePrice';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $session = (new ShopifyService)->getSession();

                $count = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->count();
                $this->info("Remaining {$count}");

                while ($count) {
                    $variant = ShopifyProductVariant::with('retailEdgeProduct')->whereNotNull('variant_id')->where('price_requires_update', 1)->first();

                    if ($variant) {
                        try {
                            $v = new Variant($session);
                            $v->id = $variant->variant_id;
                            $v->price = $variant->price;
                            $v->compare_at_price = $variant->compare_at_price;
                            $v->save(
                                true, // Update Object
                            );

                            $this->info("Price updated for id {$variant->id}, sku {$variant->sku}, variant id {$variant->variant_id}");

                            $variant->update(['price' => $variant->price, 'compare_at_price' => $variant->compare_at_price, 'price_requires_update' => 0]);
                        } catch (\Exception $e) {
                            Log::debug("There was an error while updating the price to {$variant->price} for {$variant->sku}. Error message : {$e->getMessage()}");
                            $variant->update(['price_requires_update' => 2]);
                        }
                        usleep(1500000);
                    }

                    $count = ShopifyProductVariant::whereNotNull('variant_id')->where('price_requires_update', 1)->count();
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
