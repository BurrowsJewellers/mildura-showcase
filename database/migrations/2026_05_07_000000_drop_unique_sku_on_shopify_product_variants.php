<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the unique SKU constraint on shopify_product_variants.
     *
     * The constraint hid duplicates from the local mirror — when Shopify
     * had two variants with the same SKU on different products, only the
     * first insert succeeded and the second was silently dropped, leaving
     * the dedupe commands with nothing to find. Removing it lets the
     * mirror reflect Shopify reality so shopify:delete-duplicate-variants
     * and shopify:delete-duplicate-products can detect and clean up.
     *
     * Idempotent: only drops the index if it actually exists, so the
     * migration is safe across environments where the index may or may
     * not have been previously applied.
     */
    public function up(): void
    {
        if ($this->indexExists('shopify_product_variants', 'shopify_product_variants_sku_unique')) {
            Schema::table('shopify_product_variants', function (Blueprint $table) {
                $table->dropUnique('shopify_product_variants_sku_unique');
            });
        }
    }

    public function down(): void
    {
        if (! $this->indexExists('shopify_product_variants', 'shopify_product_variants_sku_unique')) {
            Schema::table('shopify_product_variants', function (Blueprint $table) {
                $table->unique('sku', 'shopify_product_variants_sku_unique');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        );

        return ((int) ($row->cnt ?? 0)) > 0;
    }
};
