<?php

namespace App\Exceptions;

use Exception;

/**
 * Carries Shopify Admin GraphQL error context (API code + throttleStatus) so
 * retry logic can decide whether to retry, and how long to wait. Thrown from
 * ShopifyGraphQLService::query() when the response body has an errors[] array.
 */
class ShopifyGraphQLException extends Exception
{
    /** Error codes that won't change on retry — fail fast instead of looping. */
    public const NON_RETRIABLE_CODES = [
        'MAX_COST_EXCEEDED',
        'ACCESS_DENIED',
        'SHOP_INACTIVE',
    ];

    public ?string $apiCode = null;

    public ?string $requestId = null;

    public array $apiErrors = [];

    public ?array $cost = null;

    public static function fromErrors(array $errors, ?array $cost = null): self
    {
        $first = $errors[0] ?? [];
        $extensions = $first['extensions'] ?? [];

        $instance = new self($first['message'] ?? 'Unknown GraphQL error');
        $instance->apiCode = $extensions['code'] ?? null;
        $instance->requestId = $extensions['requestId'] ?? null;
        $instance->apiErrors = $errors;
        $instance->cost = $cost;

        return $instance;
    }

    public function isRetriable(): bool
    {
        return ! in_array($this->apiCode, self::NON_RETRIABLE_CODES, true);
    }

    public function isThrottled(): bool
    {
        return $this->apiCode === 'THROTTLED';
    }

    /**
     * Seconds to wait before retrying a THROTTLED request, derived from
     * Shopify's throttleStatus (currentlyAvailable / restoreRate). Returns
     * null when the response didn't include cost info — caller should fall
     * back to its own backoff.
     */
    public function suggestedRetryDelaySeconds(): ?float
    {
        if (! $this->isThrottled() || empty($this->cost)) {
            return null;
        }

        $requested = (float) ($this->cost['requestedQueryCost'] ?? 0);
        $available = (float) ($this->cost['throttleStatus']['currentlyAvailable'] ?? 0);
        $rate = (float) ($this->cost['throttleStatus']['restoreRate'] ?? 50.0);

        if ($rate <= 0) {
            return 1.0;
        }

        $shortfall = max(0.0, $requested - $available);

        return max(1.0, $shortfall / $rate);
    }
}
