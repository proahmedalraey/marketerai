<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تجارب عملاء حقيقية يدخلها التاجر بنفسه (قرار §9-5 في خطة التدقيق).
 *
 * لا يُقتبس في منشور إلا ما هنا، وبنصه. والإذن شرط الإدخال: نشر اسم
 * عميل وكلامه بلا إذنه مسؤولية على التاجر لا ميزة تسويقية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            // فارغ = تجربة عن المتجر لا عن منتج بعينه
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('author_name', 80);
            // full | first_name | anonymous
            $table->string('display_as', 16)->default('first_name');
            $table->text('body');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('source', 60)->nullable();
            $table->timestamp('consented_at');
            $table->timestamps();

            $table->index(['brand_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
