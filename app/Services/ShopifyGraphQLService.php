<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;
use Shopify\Clients\HttpResponse;

class ShopifyGraphQLService extends ShopifyConnectionService
{
    protected Graphql $client;

    /**
     * Initialize GraphQL client
     */
    public function __construct()
    {
        $session = $this->getSession();
        $this->client = new Graphql($session->getShop(), $session->getAccessToken());
    }

    /**
     * Execute a GraphQL query
     *
     * @throws Exception
     */
    public function query(string $query, array $variables = []): array
    {
        try {
            /** @var HttpResponse */
            $response = $this->client->query([
                'query' => $query,
                'variables' => $variables,
            ]);

            $body = $response->getDecodedBody();

            // Check for GraphQL errors
            if (isset($body['errors']) && ! empty($body['errors'])) {
                $errorMessages = array_map(function ($error) {
                    return $error['message'] ?? 'Unknown error';
                }, $body['errors']);

                $errorString = implode(', ', $errorMessages);
                Log::error('GraphQL query error: '.$errorString);
                throw new Exception('GraphQL query error: '.$errorString);
            }

            // Check for user errors in mutations
            if (isset($body['data']) && $this->hasUserErrors($body['data'])) {
                $userErrors = $this->extractUserErrors($body['data']);
                if (! empty($userErrors)) {
                    $errorString = implode(', ', $userErrors);
                    Log::warning('GraphQL user errors: '.$errorString);
                }
            }

            return $body['data'] ?? [];

        } catch (Exception $e) {
            Log::error('GraphQL request failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Execute a GraphQL mutation
     *
     * @throws Exception
     */
    public function mutate(string $mutation, array $variables = []): array
    {
        // Mutations use the same endpoint as queries
        return $this->query($mutation, $variables);
    }

    /**
     * Check if response contains user errors
     */
    protected function hasUserErrors(array $data): bool
    {
        foreach ($data as $value) {
            if (is_array($value) && isset($value['userErrors']) && ! empty($value['userErrors'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract user errors from response
     */
    protected function extractUserErrors(array $data): array
    {
        $errors = [];
        foreach ($data as $value) {
            if (is_array($value) && isset($value['userErrors'])) {
                foreach ($value['userErrors'] as $error) {
                    $field = $error['field'][0] ?? 'unknown';
                    $message = $error['message'] ?? 'Unknown error';
                    $errors[] = "$field: $message";
                }
            }
        }

        return $errors;
    }

    /**
     * Handle pagination for GraphQL queries
     *
     * @param  string  $connectionPath  Path to the connection in the response (e.g., 'products')
     */
    public function paginate(string $query, array $variables, callable $callback, string $connectionPath): void
    {
        $hasNextPage = true;
        $cursor = null;

        while ($hasNextPage) {
            // Update cursor for pagination
            if ($cursor) {
                $variables['after'] = $cursor;
            }

            $response = $this->query($query, $variables);

            // Navigate to the connection
            $connection = $this->getNestedValue($response, $connectionPath);

            if (! $connection) {
                Log::warning("Connection path '$connectionPath' not found in response");
                break;
            }

            // Process nodes
            if (isset($connection['nodes'])) {
                foreach ($connection['nodes'] as $node) {
                    $callback($node);
                }
            } elseif (isset($connection['edges'])) {
                foreach ($connection['edges'] as $edge) {
                    $callback($edge['node']);
                }
            }

            // Advance cursor from pageInfo. Trusting endCursor on every page
            // (rather than only when $cursor is empty) is what keeps long
            // walks moving — the prior `! $cursor` guard pinned the cursor
            // to page 1 and made every subsequent loop refetch page 2.
            $pageInfo = $connection['pageInfo'] ?? null;
            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $cursor = $hasNextPage ? ($pageInfo['endCursor'] ?? null) : null;

            if ($hasNextPage && ! $cursor) {
                Log::warning("Pagination stopped: hasNextPage true but no endCursor for '$connectionPath'");
                break;
            }

            // Rate limit protection
            usleep(500000); // 0.5 second delay between requests
        }
    }

    /**
     * Get nested value from array using dot notation
     *
     * @return mixed|null
     */
    protected function getNestedValue(array $array, string $path)
    {
        $keys = explode('.', $path);
        $value = $array;

        foreach ($keys as $key) {
            if (! isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Build GraphQL cost estimate for a query
     * Helps with rate limiting management
     */
    public function estimateQueryCost(int $requestedObjects, int $fieldsPerObject = 10): int
    {
        // Shopify's rough cost calculation
        // Base cost + (objects * fields)
        return 1 + ($requestedObjects * $fieldsPerObject);
    }

    /**
     * Format GraphQL ID from REST ID
     *
     * @param  string|int  $restId
     */
    public function formatGraphQLId(string $resourceType, $restId): string
    {
        return "gid://shopify/$resourceType/$restId";
    }

    /**
     * Extract REST ID from GraphQL ID. Anchored so a string with junk on
     * either side (e.g. an injected GID embedded in a free-form value) does
     * not match.
     */
    public function extractRestId(string $graphqlId): ?string
    {
        if (preg_match('#^gid://shopify/\w+/(\d+)$#', $graphqlId, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
