<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 32);        // content | image | carousel_images | voice | video
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('status', 16)->default('queued'); // queued | processing | completed | partial | failed | cancelled

            $table->json('payload')->nullable();  // مدخلات المهمة
            $table->json('result')->nullable();   // مخرجات مختصرة أو معرفات
            $table->text('error')->nullable();

            $table->unsignedInteger('credits_held')->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);

            // للمهام المركبة مثل صور الكاروسيل
            $table->foreignId('parent_id')->nullable();
            $table->unsignedSmallInteger('children_total')->default(0);
            $table->unsignedSmallInteger('children_done')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status', 'created_at']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_jobs');
    }
};
