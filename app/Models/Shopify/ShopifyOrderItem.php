<?php

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'line_id',
        'order_id',
        'product_id',
        'variant_id',
        'name',
        'sku',
        'quantity',
        'current_quantity',
        'fulfillable_quantity',
        'price',
    ];
}
