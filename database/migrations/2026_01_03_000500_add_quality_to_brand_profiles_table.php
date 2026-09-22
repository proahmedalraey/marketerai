<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نتيجة فحص الجودة لكل نسخة مولّدة: الدرجة، والمشاكل، وعدد المحاولات.
 *
 * تُحفظ مع النسخة لا تُحسب عند العرض، لأنها تصف ما حدث وقت التوليد
 * (هل احتاج تصحيحاً؟) — ولأن تغيّر قواعد الفحص لاحقاً لا يجب أن يغيّر
 * حكماً صدر على نسخة قديمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table) {
            // null = نسخة يدوية أو قديمة لم تُفحص
            $table->json('quality')->nullable()->after('technical');
        });
    }

    public function down(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->dropColumn('quality');
        });
    }
};
