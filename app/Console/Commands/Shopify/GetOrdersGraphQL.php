<?php

namespace App\Console\Commands\Shopify;

use App\Models\EWeb\RetailEdgeProduct;
use App\Models\Shopify\ShopifyOrder;
use App\Models\Shopify\ShopifyOrderItem;
use App\Services\EWebConnectionService;
use App\Services\GraphQL\OrderQueries;
use App\Services\ShopifyGraphQLService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GetOrdersGraphQL extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:get-orders-graphql';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get orders from Shopify using GraphQL API';

    protected ShopifyGraphQLService $graphqlService;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $this->graphqlService = new ShopifyGraphQLService;

            $this->getOrders();
            $this->pushOrdersToRetailEdge();
        } catch (\Exception $e) {
            report($e);
            $this->error($e->getMessage());
        }
    }

    /**
     * Get orders from Shopify using GraphQL
     */
    protected function getOrders()
    {
        try {
            $this->info('Fetching orders from Shopify...');

            // Build query for orders updated in the last 35 minutes
            $updatedAtMin = now()->subMinutes(35)->toIso8601String();
            $query = "updated_at:>'{$updatedAtMin}'";

            $ordersProcessed = 0;

            // Use pagination to fetch all matching orders
            $this->graphqlService->paginate(
                OrderQueries::getRecentOrders(),
                [
                    'first' => 50,
                    'updatedAt' => $query,
                ],
                function ($orderData) use (&$ordersProcessed) {
                    try {
                        DB::beginTransaction();

                        $shopifyOrder = $this->updateOrCreateShopifyOrder($orderData);
                        $this->updateOrCreateShopifyOrderItems($orderData, $shopifyOrder);

                        DB::commit();
                        $ordersProcessed++;

                        $this->info("Processed order: {$shopifyOrder->order_number}");
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->error('Error processing order: '.$e->getMessage());
                        Log::error('Error processing order from GraphQL: '.$e->getMessage());
                    }
                },
                'orders'
            );

            $this->info("Total orders processed: {$ordersProcessed}");

        } catch (\Exception $e) {
            $this->error('Error fetching orders: '.$e->getMessage());
            Log::error('Error fetching orders via GraphQL: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Create or update Shopify order in database
     */
    private function updateOrCreateShopifyOrder(array $orderData): ShopifyOrder
    {
        // Extract REST order ID from GraphQL ID
        $orderId = $this->graphqlService->extractRestId($orderData['id']);

        // Extract order number from name (e.g., "#1001" -> "1001")
        $orderNumber = preg_replace('/[^0-9]/', '', $orderData['name']);

        // Extract customer info
        $customerFirstName = $orderData['shippingAddress']['firstName'] ??
                           ($orderData['customer']['firstName'] ?? '');
        $customerLastName = $orderData['shippingAddress']['lastName'] ??
                          ($orderData['customer']['lastName'] ?? '');
        $customerEmail = $orderData['email'] ??
                        ($orderData['customer']['email'] ?? null);
        $customerPhone = $orderData['phone'] ??
                        ($orderData['customer']['phone'] ?? null);

        return ShopifyOrder::updateOrCreate(
            [
                'order_id' => $orderId,
            ],
            [
                'order_number' => $orderNumber,
                'order_date' => Carbon::parse($orderData['createdAt']),
                'cancelled_at' => isset($orderData['cancelledAt']) ? Carbon::parse($orderData['cancelledAt']) : null,
                'fulfillment_status' => strtolower($orderData['displayFulfillmentStatus'] ?? 'unfulfilled'),
                'tags' => implode(',', $orderData['tags'] ?? []),
                'customer_first_name' => $customerFirstName,
                'customer_last_name' => $customerLastName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'customer_address' => trim(
                    ($orderData['shippingAddress']['address1'] ?? '').' '.
                    ($orderData['shippingAddress']['address2'] ?? '')
                ),
                'customer_suburb' => $orderData['shippingAddress']['city'] ?? null,
                'customer_state' => $orderData['shippingAddress']['province'] ?? null,
                'customer_postcode' => $orderData['shippingAddress']['zip'] ?? null,
                'customer_country' => $orderData['shippingAddress']['country'] ?? null,
                'total_line_items_price' => $orderData['subtotalPriceSet']['shopMoney']['amount'] ?? 0,
                'subtotal_price' => $orderData['subtotalPriceSet']['shopMoney']['amount'] ?? 0,
                'total_shipping' => $orderData['totalShippingPriceSet']['shopMoney']['amount'] ?? 0,
                'total_tax' => $orderData['totalTaxSet']['shopMoney']['amount'] ?? 0,
                'total_discounts' => $orderData['totalDiscountsSet']['shopMoney']['amount'] ?? 0,
                'total_price' => $orderData['totalPriceSet']['shopMoney']['amount'] ?? 0,
            ]
        );
    }

    /**
     * Create or update order items in database
     */
    private function updateOrCreateShopifyOrderItems(array $orderData, ShopifyOrder $shopifyOrder): void
    {
        if (! isset($orderData['lineItems']['nodes'])) {
            return;
        }

        foreach ($orderData['lineItems']['nodes'] as $item) {
            // Extract REST IDs
            $lineId = $this->graphqlService->extractRestId($item['id']);
            $variantId = isset($item['variant']['id']) ?
                        $this->graphqlService->extractRestId($item['variant']['id']) : null;
            $productId = isset($item['variant']['product']['id']) ?
                        $this->graphqlService->extractRestId($item['variant']['product']['id']) : null;

            ShopifyOrderItem::updateOrCreate(
                [
                    'line_id' => $lineId,
                ],
                [
                    'order_id' => $shopifyOrder->order_id,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'name' => $item['name'],
                    'sku' => $item['sku'] ?? ($item['variant']['sku'] ?? null),
                    'quantity' => $item['quantity'] ?? 0,
                    'current_quantity' => $item['currentQuantity'] ?? 0,
                    'fulfillable_quantity' => $item['fulfillableQuantity'] ?? 0,
                    'price' => $item['originalUnitPriceSet']['shopMoney']['amount'] ?? 0,
                ]
            );
        }
    }

    /**
     * Push orders to RetailEdge system
     */
    private function pushOrdersToRetailEdge()
    {
        $shopifyOrders = ShopifyOrder::where('pushed_to_retail_edge', 0)
            ->with('items')
            ->get();

        if ($shopifyOrders->isEmpty()) {
            $this->info('No new orders to push to RetailEdge.');

            return;
        }

        $this->info("Pushing {$shopifyOrders->count()} orders to RetailEdge...");

        foreach ($shopifyOrders as $shopifyOrder) {
            $WebOrderLines = [];

            foreach ($shopifyOrder->items as $item) {
                $product = RetailEdgeProduct::where('sku', $item->sku)->first();

                if (! $product) {
                    $this->warn("SKU not found in RetailEdge: {$item->sku}");

                    continue;
                }

                $skuParts = explode('-', $item->sku);
                $stockNum = end($skuParts);

                $WebOrderLines[] = [
                    'CategoryID' => $product->category_id,
                    'SKU' => $product->origional_sku,
                    'StockNum' => $stockNum,
                    'LineNum' => $stockNum,
                    'Quantity' => $item->quantity,
                    'UnitSellPrice' => $item->price,
                    'UnitSellTax' => 0,
                    'UnitFullPrice' => $item->price,
                    'UnitFullTax' => 0,
                    'ItemDescription' => $product->title,
                    'IsNote' => false,
                    'DesignNumber' => $product->real_design_number,
                ];
            }

            if (empty($WebOrderLines)) {
                $this->warn("No valid line items for order {$shopifyOrder->order_number}. Skipping.");

                continue;
            }

            // Create order data structure
            $orderData = [
                'OrderToUpload' => [
                    'CustomerFirstName' => $shopifyOrder->customer_first_name,
                    'CustomerID' => $shopifyOrder->customer_email ?: $shopifyOrder->order_id,
                    'CustomerLastName' => $shopifyOrder->customer_last_name,
                    'CustomerEmail' => $shopifyOrder->customer_email,
                    'CustomerPhone' => $shopifyOrder->customer_phone,
                    'CustomerAddress' => $shopifyOrder->customer_address,
                    'CustomerSuburb' => $shopifyOrder->customer_suburb,
                    'CustomerState' => $shopifyOrder->customer_state,
                    'CustomerPostcode' => $shopifyOrder->customer_postcode,
                    'CustomerCountry' => $shopifyOrder->customer_country,
                    'DeliveryType' => 'ShipToAddress',
                    'OrderDate' => Carbon::parse($shopifyOrder->order_date)
                        ->timezone('Australia/Melbourne')
                        ->format('c'),
                    'OrderID' => $shopifyOrder->order_number,
                    'StoreID' => 1,
                    'Lines' => [
                        'WebOrderLine' => $WebOrderLines,
                    ],
                    'ShippingPrice' => $shopifyOrder->total_shipping,
                    'ShippingPriceExTax' => $shopifyOrder->total_shipping,
                    'ShippingTax' => 0,
                    'TotalFullPrice' => $shopifyOrder->subtotal_price,
                    'TotalFullPriceExTax' => $shopifyOrder->subtotal_price,
                    'TotalFullTax' => 0,
                    'TotalSellPrice' => $shopifyOrder->subtotal_price,
                    'TotalSellPriceExTax' => $shopifyOrder->subtotal_price,
                    'TotalSellTax' => 0,
                ],
            ];

            // Create instance of EWeb service
            $ewebService = new EWebConnectionService;

            try {
                // Make the call
                $response = $ewebService->call('UploadWebOrder', $orderData);

                // Handle the response
                if ($response) {
                    $this->info("Order {$shopifyOrder->order_number} uploaded successfully to RetailEdge");
                    $shopifyOrder->update(['pushed_to_retail_edge' => 1]);
                    Log::info("Order {$shopifyOrder->order_number} pushed to RetailEdge");
                }
            } catch (\SoapFault $e) {
                $this->error("SOAP Fault for order {$shopifyOrder->order_number}: {$e->faultstring}");
                Log::error("SOAP Fault pushing order {$shopifyOrder->order_number}: {$e->faultstring}");
            } catch (\Exception $e) {
                $this->error("Error pushing order {$shopifyOrder->order_number}: ".$e->getMessage());
                Log::error("Error pushing order {$shopifyOrder->order_number}: ".$e->getMessage());
            }
        }
    }
}
