<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقائق المتجر العامة: التوصيل، الشحن المجاني، الفروع، الجملة، الدفع، الاسترجاع.
 *
 * منظّمة لا نصاً حراً في الملاحظات: «لا يوجد شحن مجاني» في نص حر تحوي
 * كلمة «مجاني»، فتصير عند الفحص إذناً بذكره. البنية تسمح بذكر الموجب وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->json('store_facts')->nullable()->after('links');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('store_facts');
        });
    }
};
