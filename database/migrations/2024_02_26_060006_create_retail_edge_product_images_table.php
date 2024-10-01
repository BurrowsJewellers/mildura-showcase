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
        Schema::create('retail_edge_product_images', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->index();
            $table->integer('e_web_index')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('url', 600);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retail_edge_product_images');
    }
};
