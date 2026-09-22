<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generation_job_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 16); // image | video | audio
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('mime', 64)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            // البرومبت والبذرة: يتيحان «أعد بنفس النمط» لاحقاً
            $table->text('prompt')->nullable();
            $table->string('seed', 64)->nullable();
            $table->json('meta')->nullable();

            $table->unsignedSmallInteger('slide_index')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'kind', 'created_at']);
            $table->index(['content_item_id', 'slide_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
