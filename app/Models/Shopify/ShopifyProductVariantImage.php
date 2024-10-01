<?php

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyProductVariantImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'variant_id',
        'image_id',
        'url',
        'position',
        'requires_update',
    ];
}
