<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // دفتر أستاذ لا يُعدَّل ولا يُحذف منه سطر. الرصيد على brands مجرد لقطة سريعة.
        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_job_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('delta');              // سالب = خصم، موجب = إضافة أو إرجاع
            $table->integer('balance_after');
            $table->string('reason', 48);          // grant | hold | settle | refund | expire | admin_adjust
            $table->string('operation', 64)->nullable(); // content.carousel, image.high_2k ...
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'created_at']);
            $table->index(['brand_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
    }
};
