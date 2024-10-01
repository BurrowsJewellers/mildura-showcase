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
        Schema::create('shopify_webhooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('webhook_id');
            $table->string('address', 600);
            $table->string('topic');
            $table->string('format');
            $table->string('api_version')->nullable();
            $table->timestamp('webhook_created_at')->nullable();
            $table->timestamp('webhook_updated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_webhooks');
    }
};
