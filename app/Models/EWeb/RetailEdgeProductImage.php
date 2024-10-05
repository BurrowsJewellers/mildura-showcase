<?php

namespace App\Models\EWeb;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RetailEdgeProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'e_web_index',
        'width',
        'height',
        'url',
    ];
}
