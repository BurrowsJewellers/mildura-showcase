<?php

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'name',
        'address1',
        'address2',
        'city',
        'zip',
        'province',
        'country',
        'phone',
        'country_code',
        'country_name',
        'province_code',
        'active',
    ];
}
