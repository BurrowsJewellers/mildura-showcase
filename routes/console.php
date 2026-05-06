<?php

use App\Console\Commands\EWeb\GetBrandsFromEWeb;
use App\Console\Commands\EWeb\GetProductsFromEWeb;
use App\Console\Commands\Shopify\ArchiveProductsGraphQL;
use App\Console\Commands\Shopify\DedupeProducts;
use App\Console\Commands\Shopify\GetOrdersGraphQL;
use App\Console\Commands\Shopify\GetProductsGraphQL;
use App\Console\Commands\Shopify\UploadImagesGraphQL;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command(GetBrandsFromEWeb::class)
    ->daily()
    ->withoutOverlapping();

Schedule::command(UploadImagesGraphQL::class)
    ->everyThreeHours()
    ->withoutOverlapping();

Schedule::command(GetOrdersGraphQL::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command(GetProductsFromEWeb::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->after(function () {
        Artisan::call('shopify:update-inventory-graphql');
        Artisan::call('shopify:update-price-graphql');
        Artisan::call('shopify:create-product-graphql');
    });

// Refresh local mirror, then dedupe parent-level then variant-level. Order
// matters: delete-duplicate-products consolidates each parent SKU to one
// Shopify product so the variant pass has a stable parent → product
// mapping when it picks the "correct" host for child SKUs.
Schedule::command(GetProductsGraphQL::class)
    ->cron('5 */4 * * *')
    ->withoutOverlapping()
    ->after(function () {
        Artisan::call('shopify:delete-duplicate-products', ['--force' => true]);
        Artisan::call('shopify:delete-duplicate-variants', ['--force' => true]);
    });

Schedule::command(ArchiveProductsGraphQL::class)
    ->hourly()
    ->withoutOverlapping();

// Dry-run dedupe report on a weekly cadence — prints duplicates if any
// re-emerge but never archives without --apply. Re-run manually with
// `--apply` after reviewing the output.
Schedule::command(DedupeProducts::class)
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping();
