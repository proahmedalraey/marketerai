<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();

            $table->string('platform', 32);
            $table->string('external_id');
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('avatar_url')->nullable();

            // التوكنات تُخزن مشفرة عبر cast في النموذج
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();

            $table->string('status', 16)->default('connected'); // connected | expired | revoked
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'platform', 'external_id']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
