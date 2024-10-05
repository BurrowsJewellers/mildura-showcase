<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class EWebService extends EWebConnectionService
{

    public function getAllActiveItems()
    {
        $resp = $this->call('GetAllActiveItems');
        $activeItems = $resp->GetAllActiveItemsResult->ActiveItem;
        Storage::put('retail_edge.json', json_encode($activeItems));
        return $activeItems;
    }
}
