<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مكتبة شعارات بدل شعار واحد.
 *
 * العلامة الواحدة تملك شعاراً أفقياً وآخر مربعاً وثالثاً أحادي اللون،
 * ويختلف الصالح منها باختلاف التصميم. نبقي brands.logo_path مرآةً
 * للشعار المفضّل حتى لا ينكسر ما يقرأه اليوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_logos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('label')->nullable();

            // المفضّل: الشعار الذي يُركَّب تلقائياً على التصاميم
            $table->boolean('is_default')->default(false);
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['brand_id', 'sort']);
            $table->index(['brand_id', 'is_default']);
        });

        // الشعار القائم يصبح أول عنصر في المكتبة ومفضّلاً،
        // وإلا بدت مكتبة الشعارات فارغة لمن رفع شعاره أمس.
        DB::table('brands')
            ->whereNotNull('logo_path')
            ->orderBy('id')
            ->chunkById(100, function ($brands) {
                $rows = [];

                foreach ($brands as $brand) {
                    $rows[] = [
                        'brand_id' => $brand->id,
                        'disk' => 'public',
                        'path' => $brand->logo_path,
                        'label' => 'شعار الهوية',
                        'is_default' => true,
                        'sort' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::table('brand_logos')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_logos');
    }
};
