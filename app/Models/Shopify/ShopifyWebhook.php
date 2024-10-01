<?php

namespace App\Models\Shopify;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'webhook_id',
        'address',
        'topic',
        'format',
        'api_version',
        'webhook_created_at',
        'webhook_updated_at',
    ];
}
