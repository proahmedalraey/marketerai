<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نتيجة فحص الصدق لكل منشور: الدرجة، والمشاكل، وعدد المحاولات.
 *
 * بخلاف ملف الهوية، يُعاد الفحص عند كل تعديل يدوي: التاجر يصحّح النص
 * بنفسه، والتنبيه يجب أن يختفي حين يختفي سببه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            // null = محتوى وُلّد قبل بوابة الصدق ولم يُفحص
            $table->json('quality')->nullable()->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('quality');
        });
    }
};
