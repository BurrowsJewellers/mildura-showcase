<?php

namespace App\Services;

use Shopify\ApiVersion;
use Shopify\Auth\Session;
use Shopify\Context;
use Shopify\Auth\FileSessionStorage;

class ShopifyConnectionService
{

    public function getSession(): Session
    {
        Context::initialize(
            apiKey: config('shopify.api_key'),
            apiSecretKey: config('shopify.api_secret_key'),
            scopes: ['NA'],
            hostName: config('shopify.store_name'),
            sessionStorage: new FileSessionStorage(storage_path()),
            apiVersion: ApiVersion::LATEST,
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
