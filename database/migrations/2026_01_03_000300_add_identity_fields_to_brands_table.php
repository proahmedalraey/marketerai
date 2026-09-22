<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الهوية البصرية تتجاوز «قائمة أكواد ألوان».
 *
 * اللون بلا دور لا يفيد نموذج الصور: عليه أن يعرف أيّها خلفية وأيّها نص.
 * لذلك يتحول colors من مصفوفة نصوص إلى مصفوفة كائنات {hex, name, role}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            // {ar_primary, ar_secondary, en_primary, en_secondary}
            $table->json('fonts')->nullable()->after('colors');

            // أنماط وأشكال تُمرَّر كمرجع بصري — بسقف يفرضه التطبيق
            $table->json('patterns')->nullable()->after('fonts');

            // الخلاصة التصميمية: توجيه أسلوبي حر يُحقن في برومبت الصور
            $table->text('design_summary')->nullable()->after('visual_style');
        });

        $this->reshapeColors(
            fn (array $colors) => collect($colors)
                ->filter(fn ($color) => is_string($color) && trim($color) !== '')
                ->values()
                ->map(fn ($hex, $index) => [
                    'hex' => static::normalizeHex($hex),
                    'name' => null,
                    'role' => $index === 0 ? 'primary' : 'secondary',
                ])
                ->all()
        );
    }

    public function down(): void
    {
        $this->reshapeColors(
            fn (array $colors) => collect($colors)
                ->map(fn ($color) => is_array($color) ? ($color['hex'] ?? null) : $color)
                ->filter()
                ->values()
                ->all()
        );

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['fonts', 'patterns', 'design_summary']);
        });
    }

    /**
     * يمرّ على كل علامة ويعيد كتابة عمود الألوان بالشكل الجديد.
     * الصفوف المكتوبة بالشكل الهدف أصلاً تُترك كما هي.
     */
    protected function reshapeColors(callable $transform): void
    {
        DB::table('brands')
            ->whereNotNull('colors')
            ->orderBy('id')
            ->chunkById(100, function ($brands) use ($transform) {
                foreach ($brands as $brand) {
                    $colors = json_decode($brand->colors ?? '[]', true);

                    if (! is_array($colors) || $colors === []) {
                        continue;
                    }

                    DB::table('brands')
                        ->where('id', $brand->id)
                        ->update(['colors' => json_encode($transform($colors), JSON_UNESCAPED_UNICODE)]);
                }
            });
    }

    protected static function normalizeHex(string $hex): string
    {
        $hex = strtoupper(trim($hex));

        return str_starts_with($hex, '#') ? $hex : '#'.$hex;
    }
};
