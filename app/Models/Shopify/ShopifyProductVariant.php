<?php

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Eweb\RetailEdgeProduct;
use App\Models\Eweb\RetailEdgeProductImage;

class ShopifyProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_product_id',
        'product_id',
        'variant_id',
        'title',
        'price',
        'compare_at_price',
        'sku',
        'old_key',
        'position',
        'inventory_policy',
        'fulfillment_service',
        'inventory_management',
        'option1_type',
        'option1',
        'option2_type',
        'option2',
        'option3_type',
        'option3',
        'taxable',
        'barcode',
        'grams',
        'weight',
        'inventory_item_id',
        'inventory_quantity',
        'old_inventory_quantity',
        'requires_shipping',
        'requires_update',
        'price_requires_update',
        'inventory_requires_update',
        'images_requires_update',
    ];

    public function product()
    {
        return $this->belongsTo(ShopifyProduct::class, 'shopify_product_id');
    }

    public function images()
    {
        return $this->hasMany(RetailEdgeProductImage::class, 'sku', 'sku')->orderBy('e_web_index', 'asc');
    }

    public function retailEdgeProduct()
    {
        return $this->belongsTo(RetailEdgeProduct::class, 'sku', 'sku');
    }
}
