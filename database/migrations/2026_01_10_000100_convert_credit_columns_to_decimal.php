<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * مصفوفة نقاط استوديو الصور الجديدة (دقة × جودة) تحوي قيماً كسرية
     * مثل 0.5 و14.5 — الأعمدة الصحيحة تقصّها لصفر أو تقرّبها بصمت.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->decimal('credit_balance', 10, 1)->default(0)->change();
            $table->decimal('credits_allowance', 10, 1)->default(0)->change();
        });

        Schema::table('generation_jobs', function (Blueprint $table) {
            $table->decimal('credits_held', 10, 1)->default(0)->change();
            $table->decimal('credits_charged', 10, 1)->default(0)->change();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->decimal('delta', 10, 1)->change();
            $table->decimal('balance_after', 10, 1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->integer('credit_balance')->default(0)->change();
            $table->unsignedInteger('credits_allowance')->default(0)->change();
        });

        Schema::table('generation_jobs', function (Blueprint $table) {
            $table->unsignedInteger('credits_held')->default(0)->change();
            $table->unsignedInteger('credits_charged')->default(0)->change();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->integer('delta')->change();
            $table->integer('balance_after')->change();
        });
    }
};
