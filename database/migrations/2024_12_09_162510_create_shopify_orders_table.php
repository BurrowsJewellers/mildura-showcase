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
        Schema::create('shopify_orders', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('order_id');
            $table->string('order_number')->nullable();
            $table->timestamp('order_date');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->string('tags', 1000)->nullable();
            $table->string('customer_first_name')->nullable();
            $table->string('customer_last_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('customer_suburb')->nullable();
            $table->string('customer_state')->nullable();
            $table->string('customer_postcode')->nullable();
            $table->string('customer_country')->nullable();
            $table->decimal('total_line_items_price')->default(0);
            $table->decimal('subtotal_price')->default(0);
            $table->decimal('total_shipping')->default(0);
            $table->decimal('total_tax')->default(0);
            $table->decimal('total_discounts')->default(0);
            $table->decimal('total_price')->default(0);
            $table->boolean('pushed_to_retail_edge')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_orders');
    }
};
