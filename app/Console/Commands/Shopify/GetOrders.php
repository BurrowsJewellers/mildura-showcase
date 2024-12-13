<?php

namespace App\Console\Commands\Shopify;

use App\Models\EWeb\RetailEdgeProduct;
use App\Models\Shopify\ShopifyOrder;
use App\Models\Shopify\ShopifyOrderItem;
use App\Services\EWebConnectionService;
use App\Services\ShopifyService;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Shopify\Rest\Admin2024_07\Order;

class GetOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopifyGetOrders';

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
        try {
            $this->getOrders();
            $this->pushOrdersToRetailEdge();
        } catch (\Exception $e) {
            report($e);
            var_dump($e->getMessage());
        }
    }

    public function getOrders()
    {
        try {
            $this->info('getOrders');
            $session = (new ShopifyService)->getSession();

            $orders = Order::all(
                $session,
                [],
                [
                    "status" => "any",
                    "updated_at_min" => now()->subDays(60)->toIso8601String(),
                    "limit" => 250
                ]
            );

            foreach ($orders as $order) {
                // dd($order);
                try {
                    DB::beginTransaction();
                    $shopifyOrder = $this->updateOrCreateShopifyOrder($order);

                    $this->updateOrCreateShopifyOrderItems($order);
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        } catch (\Exception $e) {
            report($e);
        }
    }


    private function updateOrCreateShopifyOrder($order): ShopifyOrder
    {
        return ShopifyOrder::updateOrCreate(
            [
                'order_id' => $order->id
            ],
            [
                'order_number' => $order->order_number,
                'order_date' => Carbon::parse($order->created_at),
                'cancelled_at' => isset($order->cancelled_at) ? Carbon::parse($order->cancelled_at) : null,
                'fulfillment_status' => $order->fulfillment_status,
                'tags' => $order->tags ? $order->tags : null,
                'customer_first_name' => $order->shipping_address['first_name'],
                'customer_last_name' => $order->shipping_address['last_name'],
                'customer_email' => $order->contact_email,
                'customer_phone' => $order->phone,
                'customer_address' => $order->shipping_address['address1'] . ', ' . $order->shipping_address['address2'],
                'customer_suburb' => $order->shipping_address['city'],
                'customer_state' => $order->shipping_address['province'],
                'customer_postcode' => $order->shipping_address['zip'],
                'customer_country' => $order->shipping_address['country'],
                'total_line_items_price' => $order->total_line_items_price,
                'subtotal_price' => $order->subtotal_price,
                'total_shipping' => $order->total_shipping_price_set['shop_money']['amount'],
                'total_tax' => $order->total_tax,
                'total_discounts' => $order->total_discounts,
                'total_price' => $order->total_price,
            ]
        );
    }

    private function updateOrCreateShopifyOrderItems($order): void
    {
        foreach ($order->line_items as $item) {
            ShopifyOrderItem::updateOrCreate(
                [
                    'line_id' => $item['id'],
                ],
                [
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'],
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'current_quantity' => $item['current_quantity'],
                    'fulfillable_quantity' => $item['fulfillable_quantity'],
                    'price' => $item['price'],
                ]
            );
        }
    }

    private function pushOrdersToRetailEdge()
    {
        $shopifyOrders = ShopifyOrder::where('pushed_to_retail_edge', 0)->with('items')->get();

        foreach ($shopifyOrders as $shopifyOrder) {

            $WebOrderLines = [];

            foreach ($shopifyOrder->items as $item) {
                $product = RetailEdgeProduct::where('sku', $item->sku)->first();
                $skuParts = explode("-", $item->sku); 
                $stockNum = end($skuParts);

                $WebOrderLines[] = [
                    "CategoryID" => $product->category_id,
                    "SKU" => $product->origional_sku,
                    "StockNum" => $stockNum,
                    "LineNum" => $stockNum,
                    "Quantity" => $item->quantity,
                    "UnitSellPrice" => $item->price,
                    "UnitSellTax" => 0,
                    "UnitFullPrice" => $item->price,
                    "UnitFullTax" => 0,
                    "ItemDescription" => $product->title,
                    "IsNote" => false,
                    "DesignNumber" => $product->real_design_number,
                    // "Detail_Mfg" => 'Brand'
                ];
            }

            // Create order data structure
            $orderData = [
                "OrderToUpload" => [
                    "CustomerFirstName" => $shopifyOrder->customer_first_name,
                    'CustomerID' => $shopifyOrder->customer_email ? $shopifyOrder->customer_email : $shopifyOrder->id,
                    "CustomerLastName" => $shopifyOrder->customer_last_name,
                    "CustomerEmail" => $shopifyOrder->customer_email,
                    "CustomerPhone" => $shopifyOrder->customer_phone,
                    "CustomerAddress" => $shopifyOrder->customer_address,
                    "CustomerSuburb" => $shopifyOrder->customer_suburb,
                    "CustomerState" => $shopifyOrder->customer_state,
                    "CustomerPostcode" => $shopifyOrder->customer_postcode,
                    "CustomerCountry" => $shopifyOrder->customer_country,
                    "DeliveryType" => "ShipToAddress", // Pickup, ShipToAddress
                    "OrderDate" => Carbon::parse($shopifyOrder->order_date)->timezone('Australia/Melbourne')->format('c'),
                    "OrderID" => $shopifyOrder->order_number,
                    "StoreID" => 1,
                    "Lines" => [
                        "WebOrderLine" => $WebOrderLines
                    ],
                    "ShippingPrice" => $shopifyOrder->total_shipping,
                    "ShippingPriceExTax" => $shopifyOrder->total_shipping,
                    "ShippingTax" => 0,
                    "TotalFullPrice" => $shopifyOrder->subtotal_price,
                    "TotalFullPriceExTax" => $shopifyOrder->subtotal_price,
                    "TotalFullTax" => 0,
                    "TotalSellPrice" => $shopifyOrder->subtotal_price,
                    "TotalSellPriceExTax" => $shopifyOrder->subtotal_price,
                    "TotalSellTax" => 0,
                ]
            ];

            // dd($orderData);

            // Create instance of your service
            $ewebService = new EWebConnectionService();

            try {
                // Make the call
                // $response = $ewebService->call('UploadTestWebOrder', $orderData);
                $response = $ewebService->call('UploadWebOrder', $orderData);

                // Get the last request for debugging
                $lastRequest = $ewebService->getEwebSoapClient()->__getLastRequest();
                echo "Request XML:\n" . $lastRequest . "\n\n";

                // Handle the response
                if ($response) {
                    echo "Order uploaded successfully\n";
                    var_dump($response);
                    $shopifyOrder->update(['pushed_to_retail_edge' => 1]);
                }
            } catch (\SoapFault $e) {
                echo "SOAP Fault:\n";
                echo "Fault code: " . $e->faultcode . "\n";
                echo "Fault string: " . $e->faultstring . "\n";

                // Get the last request and response for debugging
                $lastRequest = $ewebService->getEwebSoapClient()->__getLastRequest();
                $lastResponse = $ewebService->getEwebSoapClient()->__getLastResponse();

                echo "\nLast Request:\n" . $lastRequest;
                echo "\nLast Response:\n" . $lastResponse;
            } catch (\Exception $e) {
                echo "General Exception: " . $e->getMessage() . "\n";
            }
        }
    }
}
