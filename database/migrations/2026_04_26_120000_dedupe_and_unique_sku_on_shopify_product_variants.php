<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedupe rows that share a SKU and add a unique index.
     *
     * MySQL implicitly commits DDL (ALTER TABLE), so a single transaction
     * cannot cover both the DELETE and the ALTER. We grab a write lock for
     * the duration so a concurrent insert cannot squeeze a fresh duplicate
     * between the DELETE and the index creation. The lock is released in
     * `finally` to avoid leaving the table locked if the migration aborts.
     */
    public function up(): void
    {
        DB::statement('LOCK TABLES shopify_product_variants WRITE');

        try {
            DB::statement('
                DELETE spv FROM shopify_product_variants spv
                INNER JOIN (
                    SELECT MIN(id) AS keep_id, sku
                    FROM shopify_product_variants
                    WHERE sku IS NOT NULL
                    GROUP BY sku
                    HAVING COUNT(*) > 1
                ) keepers ON spv.sku = keepers.sku
                WHERE spv.id <> keepers.keep_id
            ');

            Schema::table('shopify_product_variants', function (Blueprint $table) {
                $table->unique('sku', 'shopify_product_variants_sku_unique');
            });
        } finally {
            DB::statement('UNLOCK TABLES');
        }
    }

    public function down(): void
    {
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->dropUnique('shopify_product_variants_sku_unique');
        });
    }
};
