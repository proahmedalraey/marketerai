<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مصدر واحد لإجابات المشروع.
 *
 * كانت الإجابات تعيش في مكانين: أعمدة العلامة (صفحة الهوية القديمة)
 * ولقطة داخل كل نسخة من ملف المشروع. تغيير الرابط في أحدهما لا يصل
 * للآخر. الآن الأعمدة هي المصدر، واللقطة سجلٌّ لما وُلدت منه النسخة فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            // سلعة أو خدمة: يغيّر صياغة الأسئلة وما يُطلب من النموذج
            $table->string('business_type', 16)->default('good')->after('industry');

            // «ملاحظات إضافية» — السؤال الوحيد الذي لم يكن له عمود
            $table->text('profile_notes')->nullable()->after('banned_words');
        });

        // من كتب إجاباته في ملف المشروع لا يجب أن يجدها فارغة بعد الترقية
        DB::table('brand_profiles')->where('is_active', true)->orderBy('id')->each(function ($profile) {
            $answers = json_decode($profile->answers ?? '[]', true) ?: [];
            $brand = DB::table('brands')->find($profile->brand_id);

            if (! $brand) {
                return;
            }

            $updates = array_filter([
                'business_type' => in_array($answers['type'] ?? null, ['good', 'service'], true) ? $answers['type'] : null,
                'profile_notes' => filled($answers['notes'] ?? null) ? $answers['notes'] : null,
                'description' => blank($brand->description) && filled($answers['one_liner'] ?? null) ? $answers['one_liner'] : null,
                'store_url' => blank($brand->store_url) && filled($answers['store_url'] ?? null) ? $answers['store_url'] : null,
            ]);

            if ($updates !== []) {
                DB::table('brands')->where('id', $brand->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'profile_notes']);
        });
    }
};
