<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 16); // salla | zid
            $table->string('store_external_id')->nullable();
            $table->string('store_name')->nullable();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->string('status', 16)->default('connected');
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('products_synced')->default(0);
            $table->timestamps();

            $table->unique(['brand_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_integrations');
    }
};
