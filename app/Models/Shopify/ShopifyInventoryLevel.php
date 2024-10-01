<?php

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyInventoryLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'inventory_item_id',
        'available',
        'inventory_updated_at',
        'requires_update',
    ];
}
