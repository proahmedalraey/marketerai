<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المنتج هنا ليس سجلاً تجارياً بل ورقة مرجعية للنموذج.
 * لذلك نفصل ما كان حقلاً واحداً غامضاً (summary) إلى الحقول التي يحتاجها
 * البرومبت فعلاً، ويختلف المطلوب منها بين السلعة والخدمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // سلعة: ما هو، كيف يُستخدم، الخامات، المقاسات، محتويات العلبة
            $table->longText('features')->nullable()->after('summary');

            // سلعة: المواصفات التقنية أو التفصيلية
            $table->longText('specifications')->nullable()->after('features');

            // الاثنان: لمن هذا المنتج تحديداً
            $table->text('audience')->nullable()->after('specifications');

            // خدمة: ما النتائج أو التسليمات التي يحصل عليها العميل
            $table->text('deliverables')->nullable()->after('audience');

            // خدمة: تفاصيل إضافية تساعد المساعد الذكي
            $table->text('notes')->nullable()->after('deliverables');
        });

        // النص الوصفي القديم كان يعيش في summary وحده.
        // ننقله إلى features حتى لا يفقد أحد بياناته عند أول تعديل بعد الترقية.
        DB::table('products')
            ->whereNull('features')
            ->whereNotNull('summary')
            ->update(['features' => DB::raw('summary')]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['features', 'specifications', 'audience', 'deliverables', 'notes']);
        });
    }
};
