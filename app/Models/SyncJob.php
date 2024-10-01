<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'marketplace',
        'message',
        'status',
    ];

    public function isRunning(){
        return $this->status == 1 ? true : false;
    }

}
