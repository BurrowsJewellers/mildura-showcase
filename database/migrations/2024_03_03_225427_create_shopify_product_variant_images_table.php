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
        Schema::create('shopify_product_variant_images', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->unsignedBigInteger('variant_id')->nullable()->unique();
            $table->unsignedBigInteger('image_id')->nullable();
            $table->string('url', 600);
            $table->smallInteger('position')->default(1);
            $table->boolean('requires_update')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_product_variant_images');
    }
};
