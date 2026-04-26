<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedupe rows that share a SKU then add a unique index.
     *
     * Run during a deploy window — the gap between DELETE and ALTER is
     * milliseconds, so a concurrent insert of a fresh duplicate is not a
     * realistic concern. If it ever happens, the ALTER fails and the
     * migration is simply re-run.
     */
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::table('shopify_product_variants', function (Blueprint $table) {
            $table->dropUnique('shopify_product_variants_sku_unique');
        });
    }
};
