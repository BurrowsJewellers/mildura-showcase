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

Schedule::command(GetProductsGraphQL::class)
    ->cron('5 */4 * * *')
    ->withoutOverlapping();

Schedule::command(ArchiveProductsGraphQL::class)
    ->hourly()
    ->withoutOverlapping();

// Dry-run dedupe report on a weekly cadence — prints duplicates if any
// re-emerge but never archives without --apply. Re-run manually with
// `--apply` after reviewing the output.
Schedule::command(DedupeProducts::class)
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping();
