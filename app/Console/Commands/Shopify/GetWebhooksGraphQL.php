<?php

namespace App\Console\Commands\Shopify;

use App\Models\ShopifyWebhook;
use App\Services\ShopifyGraphQLService;
use App\Services\SyncJobService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GetWebhooksGraphQL extends Command
{
    protected $signature = 'shopify:get-webhooks-graphql';

    protected $description = 'List configured Shopify webhook subscriptions via GraphQL';

    public function handle()
    {
        $marketplace = 'Shopify';
        $jobType = 'shopifyGetWebhooksGraphQL';

        $job = (new SyncJobService)->claim($jobType, $marketplace);

        if (! $job) {
            Log::info("$marketplace $jobType is already running.");

            return;
        }

        try {
            Log::info("$marketplace $jobType started!");

            $service = new ShopifyGraphQLService;

            $query = <<<'GRAPHQL'
            query webhookSubscriptions($first: Int!, $after: String) {
                webhookSubscriptions(first: $first, after: $after) {
                    pageInfo { hasNextPage endCursor }
                    nodes {
                        id
                        topic
                        format
                        apiVersion { handle }
                        createdAt
                        updatedAt
                        endpoint {
                            __typename
                            ... on WebhookHttpEndpoint { callbackUrl }
                            ... on WebhookEventBridgeEndpoint { arn }
                            ... on WebhookPubSubEndpoint { pubSubProject pubSubTopic }
                        }
                    }
                }
            }
            GRAPHQL;

            $service->paginate(
                $query,
                ['first' => 100],
                function (array $node) {
                    $webhookId = (new ShopifyGraphQLService)->extractRestId($node['id']);
                    $address = match ($node['endpoint']['__typename'] ?? null) {
                        'WebhookHttpEndpoint' => $node['endpoint']['callbackUrl'] ?? null,
                        'WebhookEventBridgeEndpoint' => $node['endpoint']['arn'] ?? null,
                        'WebhookPubSubEndpoint' => trim(($node['endpoint']['pubSubProject'] ?? '').':'.($node['endpoint']['pubSubTopic'] ?? ''), ':'),
                        default => null,
                    };

                    ShopifyWebhook::updateOrCreate(
                        ['webhook_id' => $webhookId],
                        [
                            'address' => $address,
                            'topic' => $node['topic'] ?? null,
                            'format' => strtolower($node['format'] ?? ''),
                            'api_version' => $node['apiVersion']['handle'] ?? null,
                            'webhook_created_at' => isset($node['createdAt']) ? Carbon::parse($node['createdAt']) : null,
                            'webhook_updated_at' => isset($node['updatedAt']) ? Carbon::parse($node['updatedAt']) : null,
                        ]
                    );
                },
                'webhookSubscriptions'
            );

            $job->update(['status' => 0, 'message' => null]);
            Log::info("$marketplace $jobType finished!");
        } catch (\Exception $e) {
            $job->update(['status' => 0, 'message' => $e->getMessage()]);
            report($e);
            $this->error($e->getMessage());
        }
    }
}
