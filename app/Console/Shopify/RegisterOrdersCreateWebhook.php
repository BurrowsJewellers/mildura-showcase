<?php

namespace App\Console\Commands\Shopify;

use App\Models\ShopifyWebhook;
use App\Services\ShopifyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RegisterOrdersCreateWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyRegisterOrdersCreateWebhook';

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
        dd(url('/shopify/webhooks/orders/create'));
        $session = (new ShopifyService)->getSession();

        dd($session);
        $response = \Shopify\Webhooks\Registry::register(
            url('/shopify/webhooks/orders/create'),
            \Shopify\Webhooks\Topics::ORDERS_CREATE,
            $session->getShop(),
            $session->getAccessToken(),
        );

        dd($response);
        if ($response->isSuccess()) {
            $body = $response->getBody();
            var_dump($body);
            if ($body && isset($body['webhook'])) {
                $shopifyWebhook = ShopifyWebhook::updateOrCreate(
                    [
                        'webhook_id' => $body['webhook'],
                    ],
                    [
                        'address' => $body['address'],
                        'topic' => $body['topic'],
                        'format' => $body['format'],
                        'api_version' => $body['api_version'],
                        'webhook_created_at' => Carbon::parse($body['webhook_created_at']),
                        'webhook_updated_at' => Carbon::parse($body['webhook_updated_at']),
                    ]
                );

                dd($shopifyWebhook);
            }
        } else {
            Log::error("Webhook registration failed with response: \n" . var_export($response, true));
        }
    }
}
