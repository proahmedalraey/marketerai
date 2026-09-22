<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('good'); // good | service
            $table->string('title');
            $table->text('summary')->nullable();

            // ورقة المنتج المرجعية: النص المجهز الذي يُحقن في البرومبت
            $table->longText('spec_sheet')->nullable();

            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->string('sku')->nullable();
            $table->string('category')->nullable();
            $table->string('origin_country')->nullable();
            $table->json('attributes')->nullable();

            // مصدر البيانات
            $table->string('source', 16)->default('manual'); // manual | salla | zid
            $table->string('external_id')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['brand_id', 'is_active']);
            $table->unique(['brand_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
