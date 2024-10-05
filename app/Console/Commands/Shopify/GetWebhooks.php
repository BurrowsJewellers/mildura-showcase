<?php

namespace App\Console\Commands\Shopify;

use App\Http\Controllers\SyncJobController;
use App\Models\ShopifyWebhook;
use App\Services\ShopifyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Rest;

class GetWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyGetWebhooks';

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
        $jobType = 'shopifyGetWebhooks';

        $job = (new SyncJobService())->getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            try {
                Log::info("$marketplace $jobType started!");
                $job->update(['status' => 1]);

                $session = (new ShopifyService)->getSession();
                $client = new Rest($session->getShop(), $session->getAccessToken());

                $response = $client->get(path: 'webhooks');

                $body = $response->getDecodedBody();

                if (!empty($body) && isset($body['webhooks']) && count($body['webhooks']) > 0) {
                    foreach ($body['webhooks'] as $webhookData) {
                        try {
                            ShopifyWebhook::updateOrCreate(
                                [
                                    'webhook_id' => $webhookData['id'],
                                ],
                                [
                                    'address' => $webhookData['address'],
                                    'topic' => $webhookData['topic'],
                                    'format' => $webhookData['format'],
                                    'api_version' => $webhookData['api_version'],
                                    'webhook_created_at' => Carbon::parse($webhookData['created_at']),
                                    'webhook_updated_at' => Carbon::parse($webhookData['updated_at']),
                                ]
                            );
                        } catch (\Exception $e) {
                            $this->error($e->getMessage());
                        }
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
