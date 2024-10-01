<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_01\Image;
use App\Http\Controllers\SyncJobController;
use App\Models\PandoraList;
use App\Models\RetailEdgeProduct;
use App\Services\ShopifyService;
use App\Models\ShopifyProductVariant;
use App\Services\PandoraScraperService;

class UploadImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyUploadImages';

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
        $jobType = 'uploadImages';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);
                $session = (new ShopifyService)->getSession();

                $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
                $this->info("Remaining {$count}");

                while ($count) {
                    $variant = ShopifyProductVariant::where('images_requires_update', 1)->with(['images', 'product'])->first();

                    if ($variant->product->vendor == 'Pandora') {
                        $retailEdgeProduct = RetailEdgeProduct::where('sku', $variant->sku)->first();

                        if (!$retailEdgeProduct) {
                            $variant->update(['images_requires_update' => 2]);
                            continue;
                        }

                        try {
                            $images = [];
                            $sleep = 600;

                            $result = PandoraList::selectRaw("DISTINCT CASE 
                                                WHEN INSTR(`design_no`, '-') > 0 
                                                THEN LEFT(`design_no`, INSTR(`design_no`, '-') - 1)
                                                ELSE `design_no`
                                            END AS `design_no`, 
                                            `design_no` AS `org_design_no`, 
                                            `id`, `sku`, `product_name`, `product_url`, `search_response`, `discontinued`, `images`, `created_at`, `updated_at`")
                                ->whereNotNull('product_url')
                                ->whereNotNull('images')
                                ->where('design_no', $retailEdgeProduct->real_design_number)
                                ->first();

                            if ($result) {
                                $images = json_decode($result->images);
                                $sleep = 0;

                                PandoraList::create([
                                    'design_no' => $retailEdgeProduct->real_design_number,
                                    'sku' => $retailEdgeProduct->sku,
                                    'search_response' => $result->search_response,
                                    'product_name' => $result->product_name,
                                    'product_url' => $result->product_url,
                                    'product_response' => $result->product_response,
                                    'discontinued' => 0,
                                    'images' => $result->images,
                                ]);
                            } else {
                                $pandoraService = new PandoraScraperService();
                                $pandoraProduct = $pandoraService->getPandoraProductByDesignNo($retailEdgeProduct->real_design_number);

                                if (!$pandoraProduct) {
                                    $variant->update(['images_requires_update' => 2]);
                                    sleep(20);
                                    continue;
                                }

                                $images = json_decode($pandoraProduct->images);
                            }

                            if (is_array($images) && count($images)) {
                                foreach ($images as $i) {
                                    try {
                                        $image = new Image($session);
                                        $image->product_id = $variant->product_id;
                                        $image->src = $i;
                                        $image->variant_ids = [
                                            $variant->variant_id
                                        ];

                                        $image->save(
                                            true, // Update Object
                                        );
                                        $this->info("Image uploaded for sku {$variant->sku}, variant id  {$variant->variant_id}");
                                        $variant->update(['images_requires_update' => 0]);
                                    } catch (\Exception $e) {
                                        Log::debug("There was an error while uploading the images for {$variant->sku}. Error message : {$e->getMessage()}");
                                        $variant->update(['images_requires_update' => 2]);
                                    }
                                }
                                $this->info("Sleeping for {$sleep} seconds after scraping Pandora.");
                            }
                        } catch (\Exception $e) {
                            report($e);
                            $variant->update(['images_requires_update' => 2]);
                            sleep(20);
                        }
                    } else {
                        if ($variant->images) {
                            foreach ($variant->images as $i) {
                                try {
                                    $image = new Image($session);
                                    $image->product_id = $variant->product_id;
                                    $image->src = $i->url;
                                    $image->variant_ids = [
                                        $variant->variant_id
                                    ];

                                    $image->save(
                                        true, // Update Object
                                    );
                                    $this->info("Image uploaded for sku {$variant->sku}, variant id  {$variant->variant_id}");
                                    $variant->update(['images_requires_update' => 0]);
                                } catch (\Exception $e) {
                                    Log::debug("There was an error while uploading the images for {$variant->sku}. Error message : {$e->getMessage()}");
                                    $variant->update(['images_requires_update' => 2]);
                                }
                            }
                        }
                    }
                    $variant->update(['images_requires_update' => 0]);
                    $count = ShopifyProductVariant::where('images_requires_update', 1)->count();
                    $this->info("Remaining {$count}");
                }

                $job->update(['status' => 0]);

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
