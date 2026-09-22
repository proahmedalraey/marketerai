<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('industry')->nullable();
            $table->text('description')->nullable();

            // الهوية التحريرية: تُحقن في كل برومبت
            $table->text('audience')->nullable();
            $table->string('tone')->nullable();
            $table->string('dialect', 32)->default('saudi');
            $table->json('selling_points')->nullable();
            $table->json('banned_words')->nullable();

            // الهوية البصرية
            $table->string('logo_path')->nullable();
            $table->json('colors')->nullable();
            $table->string('visual_style')->nullable();

            // قنوات البيع والتواصل
            $table->string('store_url')->nullable();
            $table->string('whatsapp')->nullable();
            $table->json('links')->nullable();

            // النقاط
            $table->integer('credit_balance')->default(0);
            $table->unsignedInteger('credits_allowance')->default(0);
            $table->timestamp('credits_reset_at')->nullable();

            $table->boolean('onboarding_completed')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
