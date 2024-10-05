<?php

namespace App\Console\Commands\EWeb;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\EWebService;
use App\Services\SyncJobService;
use App\Models\Eweb\RetailEdgeProduct;
use App\Models\Eweb\RetailEdgeProductImage;

class GetProductsFromEWeb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getProductsFromEWeb';

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
        $marketplace = 'EWeb';
        $jobType = 'getProductsFromEWeb';

        $job = (new SyncJobService())->getJob($jobType, $marketplace);

        if (!$job->isRunning()) {
            $shopifySkus = [];
            Log::info("$marketplace $jobType started!");
            $job->update(['status' => 1]);

            try {
                $activeItems = (new EWebService())->getAllActiveItems();

                try {
                    $shopifySkus = RetailEdgeProduct::where('uploaded_to_shopify', 1)->pluck('sku')->toArray();

                    RetailEdgeProduct::truncate();
                    RetailEdgeProductImage::truncate();
                } catch (\Exception $e) {
                    report($e);
                }

                foreach ($activeItems as $item) {
                    try {
                        if (!preg_match('/^\d{3}-\d{3}-\d{5}$/', $item->SKU)) {
                            continue;
                        }

                        $skuArray = array_map('trim', explode('-', $item->SKU));
                        $sku = $skuArray[1] . "-" . $skuArray[2];

                        $item->OldKey = trim($item->OldKey);
                        $item->ID3 = trim($item->ID3);

                        // Loop through the ItemsIDSs and add them in the main item object
                        foreach ($item->ISDs->ItemISD as $other) {
                            $keyName = str_replace(['.', ' ', ',', '_', '\''], [], $other->Name);

                            // for 022-XXXXX and it's variants, there are two Metal Colour fields in IDs. First one has the value, but the second one is empty.
                            // if the department is 022, ignore the second one
                            if ($skuArray[1] == '022') {
                                if (!isset($item->{$keyName})) {
                                    $item->{$keyName} = trim($other->Value);
                                }
                            } else {
                                $item->{$keyName} = trim($other->Value);
                            }
                        }

                        // Set default values
                        $price = $item->RetailPrice;
                        $compareAtPrice = 0;

                        /**
                         * Old code to calculate compare at price
                         */

                        /*
                        $retailPrices = [$item->RetailPrice, $item->RetailPrice2];

                        // Convert all prices to float and filter out non-positive values
                        $prices = array_filter(array_map('floatval', $retailPrices), function ($price) {
                            return $price > 0;
                        });

                        // Find the lower price and higher compare_at_price
                        if (!empty($prices)) {
                            $price = min($prices);
                            $compareAtPrice = max($prices);
                        }

                        $compareAtPrice = ($price == $compareAtPrice) ? 0 : $compareAtPrice;
                        */

                        /**
                         * New code to calculate compare at price
                         */
                        // if ($item->SpecialPrice > 0 && isset($item->SpecialPriceEnd)) {
                        //     $specialPriceEnd = Carbon::parse($item->SpecialPriceEnd);
                        //     if ($specialPriceEnd > now()) {
                        //         $price = $item->SpecialPrice;
                        //         $compareAtPrice = $item->RetailPrice;
                        //     }
                        // }

                        /**
                         * Only check if there is special price filled
                         */
                        if (isset($item->SpecialPrice) && $item->SpecialPrice > 0) {
                            $price = $item->SpecialPrice;
                            $compareAtPrice = $item->RetailPrice;
                        }

                        RetailEdgeProduct::create(
                            [
                                'sku' => $sku,
                                'title' => trim($item->ShortMarketingDescription),
                                'marketing_description' => $item->MarketingDescription,
                                'brand_id' => trim($item->BrandID),
                                'barcode' => trim($item->Barcode),
                                'retail_price1' => $item->RetailPrice,
                                'retail_price2' => $item->RetailPrice2,
                                'price' => $price,
                                'compare_at_price' => $compareAtPrice,
                                'quantity' => intval($item->TotalAvailQOH),
                                'id1' => trim($item->ID1),
                                'id2' => trim($item->ID2),
                                'id3' => trim($item->ID3),
                                'id4' => trim($item->ID4),
                                'old_key' => trim($item->OldKey),
                                'is_valid_child' => preg_match('/^\d{3}-\d{5}$/', $item->OldKey) ? true : false,
                                'real_design_number' => trim($item->RealDesignNum),
                                'pendant_style' => isset($item->PendantStyle) ? $item->PendantStyle : null,
                                'metal_colour' => isset($item->MetalColour) ? $item->MetalColour : null,
                                's_web_menu' => isset($item->SWebMenu) ? $item->SWebMenu : null,
                                's_metal_type' => isset($item->SMetalType) ? $item->SMetalType : null,
                                's_stone_type' => isset($item->SStoneType) ? $item->SStoneType : null,
                                's_cat' => isset($item->SCat) ? $item->SCat : null,
                                's_sub_cat' => isset($item->SSubCat) ? $item->SSubCat : null,
                                'ring_size' => isset($item->RingSize) ? $item->RingSize : null,
                                'bracelet_length' => isset($item->Length) ? $item->Length : null,
                                'web_option_boolean1' => $item->WebOptionBoolean1,
                                'web_option_boolean2' => $item->WebOptionBoolean2,
                                'web_option_boolean3' => $item->WebOptionBoolean3,
                                'web_option_boolean4' => $item->WebOptionBoolean4,
                                'web_option_boolean5' => $item->WebOptionBoolean5,
                                'web_option_boolean6' => $item->WebOptionBoolean6,
                                'web_option_boolean7' => $item->WebOptionBoolean6,
                                'web_option_boolean8' => $item->WebOptionBoolean8,
                            ]
                        );

                        $productImages = [];

                        if (isset($item->Images) && isset($item->Images->ItemImage) && !empty($item->Images->ItemImage)) {
                            if (is_object($item->Images->ItemImage)) {
                                $productImages[] = [
                                    'e_web_index' => $item->Images->ItemImage->Index,
                                    'width' => $item->Images->ItemImage->Width,
                                    'height' => $item->Images->ItemImage->Height,
                                    'url' => htmlspecialchars_decode($item->Images->ItemImage->URL),
                                ];
                            } elseif (is_array($item->Images->ItemImage)) {
                                foreach ($item->Images->ItemImage as $image) {
                                    $productImages[] = [
                                        'e_web_index' => $image->Index,
                                        'width' => $image->Width,
                                        'height' => $image->Height,
                                        'url' => htmlspecialchars_decode($image->URL),
                                    ];
                                }
                            }
                        }

                        if (!empty($productImages)) {
                            foreach ($productImages as $productImage) {
                                RetailEdgeProductImage::create(
                                    [
                                        'sku' => $sku,
                                        'e_web_index' => $productImage['e_web_index'],
                                        'width' => $productImage['width'],
                                        'height' => $productImage['height'],
                                        'url' => $productImage['url'],
                                    ]
                                );
                            }
                        }
                    } catch (\Exception $e) {
                        report($e);
                    }
                }

                $job->update(['status' => 0, 'message' => null]);
            } catch (\Exception $e) {
                $job->update(['status' => 0, 'message' => $e->getMessage()]);
                report($e);
            }

            if (is_array($shopifySkus) && count($shopifySkus) > 0) {
                RetailEdgeProduct::whereIn('sku', $shopifySkus)->update(['uploaded_to_shopify' => 1]);
            }

            $sql = "UPDATE retail_edge_products
                SET uploaded_to_shopify = 1
                WHERE sku IN (SELECT sku FROM shopify_product_variants);
            ";
            DB::update($sql);


            $sql = "UPDATE shopify_product_variants
                JOIN retail_edge_products ON shopify_product_variants.sku = retail_edge_products.sku
                SET
                    shopify_product_variants.price = retail_edge_products.price,
                    shopify_product_variants.compare_at_price = retail_edge_products.compare_at_price,
                    shopify_product_variants.inventory_quantity = retail_edge_products.quantity,
                    shopify_product_variants.inventory_requires_update = CASE
                        WHEN shopify_product_variants.inventory_quantity <> retail_edge_products.quantity THEN 1
                        ELSE shopify_product_variants.inventory_requires_update
                    END,
                    shopify_product_variants.price_requires_update = CASE
                        WHEN shopify_product_variants.price <> retail_edge_products.price OR shopify_product_variants.compare_at_price <> retail_edge_products.compare_at_price THEN 1
                        ELSE shopify_product_variants.price_requires_update
                    END
                WHERE
                    shopify_product_variants.inventory_quantity <> retail_edge_products.quantity
                    OR shopify_product_variants.price <> retail_edge_products.price 
                    OR shopify_product_variants.compare_at_price <> retail_edge_products.compare_at_price;
            ";
            DB::update($sql);
            Log::info("$marketplace $jobType finished!");
        } else {
            Log::info("$marketplace $jobType is already running.");
        }
    }
}
