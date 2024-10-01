<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_01\Image;
use App\Http\Controllers\SyncJobController;
use App\Models\PandoraList;
use App\Models\ShopifyProduct;
use App\Services\ShopifyService;

class ReUploadPandoraImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyReUploadPandoraImages';

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
        $jobType = 'shopifyReUploadPandoraImages';

        $job = SyncJobController::getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);
                $session = (new ShopifyService)->getSession();

                $shopifyProducts = ShopifyProduct::where('vendor', 'Pandora')->select('id', 'product_id', 'title', 'sku', 'vendor')->with(['variants'])->get();

                foreach ($shopifyProducts as $shopifyProduct) {
                    try {
                        $this->info("=============================================================");
                        $this->info($shopifyProduct->title);

                        $skusArray = [];
                        foreach ($shopifyProduct->variants as $variant) {
                            $skusArray[] = $variant->sku;
                        }

                        $pandoraProductsCount = PandoraList::whereIn('sku', $skusArray)->whereNotNull('images')->count();

                        if ($pandoraProductsCount > 0) {

                            $this->info("Found {$pandoraProductsCount} products scraped.");
                            // Delete the existing product images from Shopify
                            $images = Image::all(
                                $session,
                                ["product_id" => $shopifyProduct->product_id]
                            );
                            foreach ($images as $image) {
                                $this->info("Image id: {$image->id}");
                                Image::delete(
                                    $session,
                                    $image->id,
                                    ["product_id" => $shopifyProduct->product_id],
                                );
                                $this->info("Image deleted");
                            }

                            // Re-upload the fresh images

                            foreach ($shopifyProduct->variants as $variant) {
                                $pandoraProduct = PandoraList::where('sku', $variant->sku)->select('id', 'sku', 'design_no', 'images')->whereNotNull('images')->first();

                                $this->info("Re-uploading images for {$pandoraProduct->sku}");

                                foreach (json_decode($pandoraProduct->images) as $i) {
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
                            }
                            sleep(5);
                        }
                    } catch (\Exception $e) {
                        report($e);
                        $this->error($e->getMessage());
                    }
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
