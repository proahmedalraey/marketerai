<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // التكلفة الحقيقية بالدولار مقابل ما خصمناه بالنقاط: مصدر تقرير الهامش.
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generation_job_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('operation', 64);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('images')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->boolean('succeeded')->default(true);
            $table->timestamps();

            $table->index(['provider', 'created_at']);
            $table->index(['brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
