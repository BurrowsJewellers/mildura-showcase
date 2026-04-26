<?php

namespace App\Console\Commands\Shopify;

use App\Models\Shopify\ShopifyProduct;
use App\Services\GraphQL\ProductMutations;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveProductsGraphQL extends Command
{
    protected $signature = 'shopify:archive-products-graphql';

    protected $description = 'Archive out-of-stock Shopify products using GraphQL API';

    protected ShopifyGraphQLService $graphqlService;

    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyArchiveProductsGraphQL';

        $job = (new SyncJobService)->claim($jobType, $marketplace);

        if (! $job) {
            Log::info("$marketplace $jobType is already running.");

            return;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $this->graphqlService = new ShopifyGraphQLService;

            $products = DB::select("
                SELECT sp.id AS pid, sp.title, sp.product_id
                FROM shopify_products sp
                LEFT JOIN shopify_product_variants spv ON sp.id = spv.shopify_product_id
                WHERE sp.status = 'active'
                GROUP BY sp.id, sp.title, sp.product_id
                HAVING COUNT(spv.id) > 0
                   AND COUNT(spv.id) = SUM(CASE WHEN spv.inventory_quantity = 0 THEN 1 ELSE 0 END)
            ");

            $count = count($products);
            $this->info("Products to archive: {$count}");

            foreach ($products as $row) {
                $this->archiveProduct($row);
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

    protected function archiveProduct(object $row): void
    {
        try {
            $productGid = $this->graphqlService->formatGraphQLId('Product', $row->product_id);

            $response = $this->graphqlService->mutate(
                ProductMutations::updateProductStatus(),
                [
                    'product' => [
                        'id' => $productGid,
                        'status' => 'ARCHIVED',
                    ],
                ]
            );

            if (isset($response['productUpdate']['product'])) {
                ShopifyProduct::where('id', $row->pid)->update(['status' => 'archived']);
                $this->info("{$row->title} marked as archived");
                Log::info("Archived Shopify product: {$row->title}");
            } else {
                Log::warning("Failed to archive {$row->title}: no product returned");
            }
        } catch (\Exception $e) {
            Log::warning("Failed to archive {$row->title}: ".$e->getMessage());
            $this->error("Failed to archive {$row->title}: ".$e->getMessage());
        }
    }
}
