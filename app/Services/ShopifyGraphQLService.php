<?php

namespace App\Services;

use App\Exceptions\ShopifyGraphQLException;
use Exception;
use Illuminate\Support\Facades\Log;
use Shopify\Clients\Graphql;
use Shopify\Clients\HttpResponse;

class ShopifyGraphQLService extends ShopifyConnectionService
{
    protected Graphql $client;

    /** Cost extensions from the most recent successful (or errored) response. */
    protected ?array $lastCost = null;

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

            try {
                $body = $response->getDecodedBody();
            } catch (\JsonException $jsonException) {
                $raw = '';
                try {
                    $response->getBody()->rewind();
                    $raw = $response->getBody()->getContents();
                } catch (\Throwable $ignored) {
                }

                $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 'unknown';
                $snippet = mb_substr(trim($raw), 0, 500);
                Log::error("GraphQL non-JSON response (HTTP {$status}): {$snippet}");
                throw new Exception("Shopify returned non-JSON response (HTTP {$status}): {$snippet}", 0, $jsonException);
            }

            // Capture cost extensions even on error responses so retry logic
            // can pace itself off the latest throttleStatus.
            $this->lastCost = $body['extensions']['cost'] ?? null;

            // Check for GraphQL errors
            if (isset($body['errors']) && ! empty($body['errors'])) {
                $exception = ShopifyGraphQLException::fromErrors($body['errors'], $this->lastCost);
                Log::error('GraphQL query error ['.($exception->apiCode ?? 'unknown').']: '.$exception->getMessage());
                throw $exception;
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

            $response = $this->queryWithRetry($query, $variables);

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

            // Pace ourselves against the leaky-bucket throttleStatus so a long
            // walk doesn't stall by burning the budget faster than it restores.
            usleep($this->computeAdaptiveSleepMicros());
        }
    }

    /**
     * Run a single GraphQL request with retry/backoff. Used by paginate so a
     * transient 5xx or non-JSON body on one page doesn't abort a long walk.
     * Mutations should keep calling query()/mutate() directly to avoid
     * accidental double-applies on retry.
     */
    protected function queryWithRetry(string $query, array $variables, int $maxAttempts = 4): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            try {
                return $this->query($query, $variables);
            } catch (ShopifyGraphQLException $e) {
                $lastException = $e;

                if (! $e->isRetriable()) {
                    Log::error("GraphQL non-retriable error [{$e->apiCode}]: ".$e->getMessage());
                    throw $e;
                }

                $attempt++;
                if ($attempt >= $maxAttempts) {
                    break;
                }

                $suggested = $e->suggestedRetryDelaySeconds();
                $delay = $suggested !== null
                    ? (int) ceil($suggested)
                    : min(30, 2 ** $attempt);

                $codeLabel = $e->apiCode ?? 'GraphQL error';
                Log::warning("GraphQL {$codeLabel} (attempt {$attempt}/{$maxAttempts}); retrying in {$delay}s: ".$e->getMessage());
                sleep($delay);
            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    break;
                }
                $delay = min(30, 2 ** $attempt);
                Log::warning("GraphQL request failed (attempt {$attempt}/{$maxAttempts}); retrying in {$delay}s: ".$e->getMessage());
                sleep($delay);
            }
        }

        throw $lastException;
    }

    /**
     * Decide how long to sleep between paginated calls. Uses the most recent
     * throttleStatus: if the bucket has plenty of headroom for two more calls
     * we keep moving with a small floor; if it's running low we wait long
     * enough to have headroom on the next request, capped at 5s so a stuck
     * read doesn't block forever.
     */
    protected function computeAdaptiveSleepMicros(): int
    {
        $cost = $this->lastCost;

        if (! $cost) {
            return 500_000;
        }

        $requested = (float) ($cost['requestedQueryCost'] ?? 0);
        $available = (float) ($cost['throttleStatus']['currentlyAvailable'] ?? 1000);
        $rate = (float) ($cost['throttleStatus']['restoreRate'] ?? 50);

        $target = max($requested, 1.0) * 2;

        if ($available >= $target || $rate <= 0) {
            return 100_000;
        }

        $seconds = ($target - $available) / $rate;
        $seconds = min(5.0, max(0.1, $seconds));

        return (int) round($seconds * 1_000_000);
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
