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
        Schema::create('shopify_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id')->index();
            $table->string('name');
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable();
            $table->string('phone')->nullable();
            $table->string('country_code')->nullable();
            $table->string('country_name')->nullable();
            $table->string('province_code')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_locations');
    }
};
