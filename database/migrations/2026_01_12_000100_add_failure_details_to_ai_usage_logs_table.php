<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المحاولات الفاشلة في سجل الاستهلاك.
 *
 * كان يُسجَّل الناجح وحده، فضاع الوقت الذي تستهلكه أخطاء 503 و429 وانقطاع الاتصال
 * وانتظار إعادة المحاولة: مهمة زمن نماذجها الناجحة 16 ثانية استغرقت 29، ولا أثر للفرق.
 * الآن كل محاولة فاشلة صف بزمنها ورمزها وما انتظرناه بعدها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            // رقم المحاولة داخل الطلب الواحد (1 = الأولى)
            $table->unsignedTinyInteger('attempt')->default(1)->after('succeeded');
            // رمز HTTP للفاشلة؛ null = انقطاع اتصال أو مهلة لا رمز لها
            $table->unsignedSmallInteger('status_code')->nullable()->after('attempt');
            // الانتظار قبل المحاولة التالية (وقت ضائع فوق زمن الطلب نفسه)
            $table->unsignedInteger('waited_ms')->default(0)->after('status_code');
            $table->string('error', 255)->nullable()->after('waited_ms');

            $table->index(['generation_job_id', 'succeeded']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropIndex(['generation_job_id', 'succeeded']);
            $table->dropColumn(['attempt', 'status_code', 'waited_ms', 'error']);
        });
    }
};
