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
        Schema::create('shopify_order_items', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('line_id');
            $table->bigInteger('order_id');
            $table->bigInteger('product_id');
            $table->bigInteger('variant_id');
            $table->string('name');
            $table->string('sku');
            $table->integer('quantity');
            $table->integer('current_quantity');
            $table->integer('fulfillable_quantity');
            $table->decimal('price', 10,2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_order_items');
    }
};
