<?php

namespace App\Services;

use App\Models\SyncJob;

class SyncJobService
{
    public function markAsFinished($id) {
        return SyncJob::where('id', $id)->update(['status' => 0]);
    }

    public function getJob($type, $marketplace) {
        return SyncJob::firstOrCreate(['type' => $type, 'marketplace' => $marketplace]);
    }
}
