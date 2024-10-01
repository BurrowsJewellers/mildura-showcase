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
        Schema::create('shopify_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable()->unique();
            $table->string('sku')->nullable();
            $table->string('title');
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->string('handle')->nullable();
            $table->text('tags')->nullable();
            $table->string('status')->nullable();
            $table->boolean('requires_update')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_products');
    }
};
