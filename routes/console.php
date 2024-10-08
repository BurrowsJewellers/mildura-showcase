<?php

use App\Console\Commands\EWeb\GetBrandsFromEWeb;
use App\Console\Commands\EWeb\GetProductsFromEWeb;
use App\Console\Commands\Shopify\GetProducts;
use App\Console\Commands\Shopify\UploadImages;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();


Schedule::command(GetBrandsFromEWeb::class)->daily();

Schedule::command(UploadImages::class)->everyThreeHours();

Schedule::command(GetProductsFromEWeb::class)->everyFifteenMinutes()->after(function(){
    Artisan::call('shopifyUpdateInventory');
    Artisan::call('shopifyUpdatePrice');
});

Schedule::command(GetProducts::class)->cron("5 */4 * * *")->after(function () {
    Artisan::call('shopifyCreateProduct');
});
