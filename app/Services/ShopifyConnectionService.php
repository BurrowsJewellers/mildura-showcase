<?php

namespace App\Services;

use Shopify\Auth\FileSessionStorage;
use Shopify\Auth\Session;
use Shopify\Context;

class ShopifyConnectionService
{
    /**
     * Pinned Shopify Admin API version. Bump intentionally on the quarterly
     * release schedule rather than tracking "LATEST". 2026-04 introduces a
     * mandatory @idempotent directive on inventorySetQuantities.
     */
    private const API_VERSION = '2026-04';

    public function getSession(): Session
    {
        Context::initialize(
            apiKey: config('shopify.api_key'),
            apiSecretKey: config('shopify.api_secret_key'),
            scopes: ['NA'],
            hostName: config('shopify.store_name'),
            sessionStorage: new FileSessionStorage(storage_path()),
            apiVersion: self::API_VERSION,
            isEmbeddedApp: false,
        );

        $session = new Session(
            id: 'NA',
            shop: config('shopify.store_name'),
            isOnline: false,
            state: 'NA'
        );

        $session->setAccessToken(config('shopify.access_token'));

        return $session;
    }
}
