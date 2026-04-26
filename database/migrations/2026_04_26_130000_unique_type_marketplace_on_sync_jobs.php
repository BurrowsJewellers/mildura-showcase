<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One sync_jobs row per (type, marketplace). Without this index,
     * concurrent first-time `claim()` calls can both pass `firstOrCreate`
     * and insert side-by-side rows, defeating the lock. The cleanup keeps
     * the lowest-id row per pair.
     */
    public function up(): void
    {
        DB::statement('LOCK TABLES sync_jobs WRITE');

        try {
            DB::statement('
                DELETE sj FROM sync_jobs sj
                INNER JOIN (
                    SELECT MIN(id) AS keep_id, type, marketplace
                    FROM sync_jobs
                    GROUP BY type, marketplace
                    HAVING COUNT(*) > 1
                ) keepers
                  ON sj.type = keepers.type
                 AND (sj.marketplace <=> keepers.marketplace)
                WHERE sj.id <> keepers.keep_id
            ');

            Schema::table('sync_jobs', function (Blueprint $table) {
                $table->unique(['type', 'marketplace'], 'sync_jobs_type_marketplace_unique');
            });
        } finally {
            DB::statement('UNLOCK TABLES');
        }
    }

    public function down(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->dropUnique('sync_jobs_type_marketplace_unique');
        });
    }
};
