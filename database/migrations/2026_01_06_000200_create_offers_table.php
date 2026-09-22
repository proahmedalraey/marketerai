<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العروض والأكواد كما أدخلها التاجر.
 *
 * الكود لا يُذكر في منشور إلا من هنا، ولا يُذكر إلا وهو سارٍ يوم التوليد.
 * «KSA96» في تدقيق المنافس كود اخترعه النموذج لليوم الوطني، وقد يحاول
 * عميل حقيقي استخدامه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            // فارغ = عرض على المتجر كله
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 160);
            // percent | amount | free_shipping | other
            $table->string('type', 16);
            $table->decimal('value', 10, 2)->nullable();
            $table->string('coupon_code', 40)->nullable();
            $table->string('conditions', 300)->nullable();

            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['brand_id', 'is_active', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
