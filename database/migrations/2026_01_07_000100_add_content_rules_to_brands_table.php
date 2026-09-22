<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قواعد الكتابة التي يكتبها التاجر بنفسه.
 *
 * كانت تعيش داخل «الملاحظات المهمة» في نسخة الملف، فتمسحها إعادة التوليد:
 * النموذج يكتب الملاحظات من جديد كل مرة. تدقيق المنافس رصد العكس نفسه (H2):
 * القيود تظهر وتختفي بين توليدين. هنا لا يمسّها التوليد أبداً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->json('content_rules')->nullable()->after('banned_words');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('content_rules');
        });
    }
};
