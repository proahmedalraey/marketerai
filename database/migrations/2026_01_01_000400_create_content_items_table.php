<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('goal', 32);
            $table->string('platform', 32);
            $table->string('format', 16);      // post | carousel | reel | thread
            $table->string('template', 48)->nullable();
            $table->string('language', 5)->default('ar');

            // المخرج المنظم من النموذج: {caption, slides[], hashtags[], script}
            $table->json('body');
            $table->text('caption')->nullable(); // نسخة مسطحة للبحث والعرض السريع

            $table->string('status', 16)->default('draft'); // draft | ready | scheduled | published | archived
            $table->date('planned_for')->nullable();

            $table->foreignId('generation_job_id')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status', 'planned_for']);
            $table->index(['brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
