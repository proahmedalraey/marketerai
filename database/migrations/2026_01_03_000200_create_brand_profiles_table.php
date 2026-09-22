<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملف هوية المشروع: ما يقرأه النموذج قبل كل توليد.
 *
 * نحفظه بنسخ لا بصف واحد. السبب عملي: «إعادة توليد الأوصاف» بضغطة
 * واحدة تُتلف ساعة تحرير يدوي إن لم تكن النسخة السابقة محفوظة.
 * ونحفظ معه الإجابات الخام لأنها مصدر كل توليد لاحق — بها نعيد
 * التوليد دون إعادة سؤال المستخدم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(false);

            // لقطة الإجابات كما كتبها المستخدم، بلا تنظيف ولا تفسير
            $table->json('answers')->nullable();

            $table->text('simple')->nullable();       // للعرض والبايو والكابشن القصير
            $table->longText('detailed')->nullable(); // لصفحات المتجر والوصف الطويل
            $table->json('technical')->nullable();    // شظية برومبت تُقرأ آلياً لا بشرياً

            // generated: توليد جديد · manual_edit: تحرير المستخدم · restored: استعادة نسخة
            $table->string('source', 16)->default('generated');

            $table->foreignId('generation_job_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['brand_id', 'version']);
            $table->index(['brand_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
    }
};
