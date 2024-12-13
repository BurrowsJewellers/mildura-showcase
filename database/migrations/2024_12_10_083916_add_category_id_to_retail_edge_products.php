<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('retail_edge_products', function (Blueprint $table) {
            $table->integer('category_id')->after('brand_id')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('retail_edge_products', function (Blueprint $table) {
            $table->dropColumn('category_id');
        });
    }
};
