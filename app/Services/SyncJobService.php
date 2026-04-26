<?php

namespace App\Services;

use App\Models\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncJobService
{
    /**
     * A job whose `updated_at` is older than this is treated as stale and
     * may be reclaimed. Sized to outlast the longest legitimate command
     * (the full product walk).
     */
    private const STALE_AFTER_MINUTES = 60;

    public function markAsFinished($id)
    {
        return SyncJob::where('id', $id)->update(['status' => 0]);
    }

    public function getJob($type, $marketplace)
    {
        return SyncJob::firstOrCreate(['type' => $type, 'marketplace' => $marketplace]);
    }

    /**
     * Atomically claim a job. Returns the claimed SyncJob, or null if
     * another process holds a fresh lock.
     *
     * Stale locks (updated_at older than STALE_AFTER_MINUTES) are reclaimed
     * with a warning logged on the job's `message` field — without this,
     * a crashed process leaves status=1 forever and the schedule jams.
     *
     * Relies on the unique (type, marketplace) index added in
     * `2026_04_26_130000_unique_type_marketplace_on_sync_jobs`. Without
     * that index, the firstOrCreate path is racy.
     */
    public function claim(string $type, string $marketplace): ?SyncJob
    {
        return DB::transaction(function () use ($type, $marketplace) {
            $job = SyncJob::firstOrCreate(
                ['type' => $type, 'marketplace' => $marketplace],
                ['status' => 0]
            );

            $job = SyncJob::where('id', $job->id)->lockForUpdate()->first();

            if ($job->isRunning() && ! $this->isStale($job)) {
                return null;
            }

            $message = null;
            if ($job->isRunning()) {
                $minutes = $job->updated_at?->diffInMinutes(now()) ?? 0;
                $message = "Reclaimed stale lock after {$minutes} minutes";
            }

            $job->update(['status' => 1, 'message' => $message]);

            return $job;
        });
    }

    private function isStale(SyncJob $job): bool
    {
        if (! $job->updated_at) {
            return false;
        }

        return $job->updated_at->lt(Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
