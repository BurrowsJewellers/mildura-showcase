<?php

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShopifyProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'title',
        'vendor',
        'product_type',
        'handle',
        'tags',
        'status',
        'requires_update',
    ];

    public function variants()
    {
        return $this->hasMany(ShopifyProductVariant::class);
    }
}
