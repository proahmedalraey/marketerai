<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حقائق البيع: ما يملأ به النموذج الفراغ اختراعاً إن غاب.
 *
 * تدقيق المنافس (P4) وجدها غائبة كلها — فاخترع النموذج كوداً وتقييمات.
 * لا تدخل الورقة المرجعية المخزّنة: السعر المخفّض ينتهي والمخزون ينفد،
 * فتُقرأ حيّة عند كل توليد (Product::salesContext).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand_name', 120)->nullable()->after('title');

            // السعر قبل الخصم، وتاريخ انتهاء السعر المخفّض
            $table->decimal('compare_at_price', 10, 2)->nullable()->after('price');
            $table->date('sale_ends_at')->nullable()->after('compare_at_price');

            // in_stock | out_of_stock | preorder
            $table->string('stock_status', 16)->nullable()->after('sale_ends_at');

            // تقييم المنتج في المتجر نفسه، كما يعرضه للمشترين
            $table->decimal('rating_value', 3, 2)->nullable()->after('stock_status');
            $table->unsignedInteger('rating_count')->nullable()->after('rating_value');

            // [{"provider": "tabby", "count": 4}]
            $table->json('installments')->nullable()->after('rating_count');
        });

        // العلامة المصنّعة كانت تسكن attributes باسم عربي: عمود مستقل يُفهرس ويُعرض
        DB::table('products')->whereNotNull('attributes')->orderBy('id')->each(function ($row) {
            $attributes = json_decode((string) $row->attributes, true) ?: [];
            $name = $attributes['العلامة التجارية'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                return;
            }

            unset($attributes['العلامة التجارية']);

            DB::table('products')->where('id', $row->id)->update([
                'brand_name' => mb_substr(trim($name), 0, 120),
                'attributes' => $attributes === [] ? null : json_encode($attributes, JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'brand_name', 'compare_at_price', 'sale_ends_at', 'stock_status',
                'rating_value', 'rating_count', 'installments',
            ]);
        });
    }
};
