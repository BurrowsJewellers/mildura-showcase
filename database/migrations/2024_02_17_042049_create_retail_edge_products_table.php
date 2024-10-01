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
        Schema::create('retail_edge_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->index();
            $table->string('title');
            $table->text('marketing_description')->nullable();
            $table->string('brand_id')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('retail_price1', 10, 2)->default(0);
            $table->decimal('retail_price2', 10, 2)->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('compare_at_price', 10, 2)->default(0);
            $table->integer('quantity')->default(0);
            $table->string('id1')->nullable();
            $table->string('id2')->nullable();
            $table->string('id3')->nullable();
            $table->string('id4')->nullable();
            $table->string('old_key')->nullable()->index();
            $table->boolean('is_valid_child')->default(false);
            $table->string('real_design_number')->nullable();
            $table->string('pendant_style')->nullable();
            $table->string('metal_colour')->nullable();
            $table->string('s_web_menu')->nullable();
            $table->string('s_metal_type')->nullable();
            $table->string('s_stone_type')->nullable();
            $table->string('s_cat')->nullable();
            $table->string('s_sub_cat')->nullable();
            $table->string('ring_size')->nullable();
            $table->string('bracelet_length')->nullable();
            $table->boolean('web_option_boolean1')->default(false);
            $table->boolean('web_option_boolean2')->default(false);
            $table->boolean('web_option_boolean3')->default(false);
            $table->boolean('web_option_boolean4')->default(false);
            $table->boolean('web_option_boolean5')->default(false);
            $table->boolean('web_option_boolean6')->default(false);
            $table->boolean('web_option_boolean7')->default(false);
            $table->boolean('web_option_boolean8')->default(false);
            $table->boolean('uploaded_to_shopify')->default(false);
            $table->boolean('uploaded_to_catch')->default(false);
            $table->boolean('uploaded_to_amazon')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retail_edge_products');
    }
};
