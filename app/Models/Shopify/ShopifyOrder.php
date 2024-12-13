<?php

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'order_number',
        'order_date',
        'cancelled_at',
        'fulfillment_status',
        'tags',
        'customer_first_name',
        'customer_last_name',
        'customer_email',
        'customer_phone',
        'customer_address',
        'customer_suburb',
        'customer_state',
        'customer_postcode',
        'customer_country',
        'total_line_items_price',
        'subtotal_price',
        'total_shipping',
        'total_tax',
        'total_discounts',
        'total_price',
        'pushed_to_retail_edge',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ShopifyOrderItem::class, 'order_id', 'order_id');
    }
}
