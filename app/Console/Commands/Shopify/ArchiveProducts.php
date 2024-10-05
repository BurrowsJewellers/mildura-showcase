<?php

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Shopify\Rest\Admin2024_07\Product;
use App\Services\ShopifyService;
use App\Services\SyncJobService;
use App\Models\Shopify\ShopifyProduct;

class ArchiveProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyArchiveProducts';

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
        $jobType = 'shopifyArchiveProducts';

        $job = (new SyncJobService())->getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $session = (new ShopifyService)->getSession();

                $sql = "SELECT
                    sp.id AS pid,
                    sp.title AS title,
                    sp.product_id AS product_id,
                    COUNT(spv.id) AS variant_count,
                    sp.status
                FROM
                    shopify_products sp
                LEFT JOIN
                    shopify_product_variants spv ON sp.id = spv.shopify_product_id
                WHERE sp.status = 'active'
                GROUP BY
                    sp.id, sp.title
                HAVING
                    COUNT(spv.id) > 0 AND COUNT(spv.id) = SUM(CASE WHEN spv.inventory_quantity = 0 THEN 1 ELSE 0 END)
                ;";

                $products = DB::select($sql);

                $count = count($products);
                $this->info("Total {$count}");

                while ($count) {
                    foreach ($products as $p) {
                        try {
                            $status = 'archived';
                            $product = new Product($session);
                            $product->id = $p->product_id;
                            $product->status = $status;
                            $product->save(
                                true,
                            );

                            ShopifyProduct::where('id', $p->pid)->update(['status' => $status]);

                            $msg = $p->title . ' marked as ' . $status;
                            $this->info($msg);
                            Log::debug($msg);
                        } catch (\Exception $e) {
                            $msg = 'There was an error while upading the status of ' . $p->title . ' to ' . $status;
                            Log::debug($msg);
                            $this->error($msg);
                        }

                        $count--;
                        $this->info("Remaining {$count}");
                        usleep(1500000);
                    }
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
