<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صفحة «كتابة المحتوى» كما عند المنافس: شكل المحتوى وخياراته، والمسودة قبل الخطة.
 *
 * variant: الشكل كما اختاره التاجر («reels»، «single_snap»). عمود format يبقى
 *          البنية (reel)، فيتشارك «Reels» و«Short» و«سنابة واحدة» فحصاً وعرضاً واحداً.
 * options: المدة أو طول الثريد، واللهجة، وسكربت التصوير — ما يلزم لإعادة المحاولة بنفسها.
 * in_plan: ما كُتب يبقى مسودة في صفحة الكتابة حتى يضيفه التاجر للخطة بتاريخ.
 *          الموجود قبل هذا التغيير كان في الخطة فيبقى فيها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('variant', 32)->nullable()->after('format');
            $table->json('options')->nullable()->after('language');
            $table->boolean('in_plan')->default(true)->after('status');

            $table->index(['brand_id', 'in_plan', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex(['brand_id', 'in_plan', 'created_at']);
            $table->dropColumn(['variant', 'options', 'in_plan']);
        });
    }
};
