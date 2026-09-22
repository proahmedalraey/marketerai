<?php

namespace App\Services\Content;

use App\Enums\ContentFormat;
use App\Models\Brand;
use App\Models\Product;

/**
 * أشكال المحتوى كما يختارها التاجر («Reels»، «Story»، «Thread»…) وما يتبعها.
 *
 * التاجر يختار الهدف والمنصة والشكل فقط؛ القالب يُختار هنا. هكذا تبقى
 * الواجهة بسيطة كما عند المنافس، ويبقى الهيكل (شرائح الكاروسيل، مشاهد
 * الفيديو) محكوماً بـ config/content.php لا متروكاً للنموذج.
 */
class ContentFormats
{
    /** @return array<string, mixed>|null */
    public static function get(?string $format): ?array
    {
        return $format ? config("content.formats.{$format}") : null;
    }

    public static function label(?string $format): ?string
    {
        return self::get($format)['label'] ?? null;
    }

    /** البنية التي يُكتب بها الشكل: قيمة عمود format في content_items. */
    public static function kind(string $format): string
    {
        return self::get($format)['kind'] ?? 'post';
    }

    /** @return array<int, string> */
    public static function forPlatform(string $platform): array
    {
        return config("content.platforms.{$platform}.formats", []);
    }

    public static function allowedOn(string $platform, string $format): bool
    {
        return in_array($format, self::forPlatform($platform), true);
    }

    /** مفتاح الحقل التابع ('duration'…) أو null. */
    public static function optionKey(string $format): ?string
    {
        return self::get($format)['option'] ?? null;
    }

    /** @return array<string, array>|null */
    public static function choices(string $format): ?array
    {
        $key = self::optionKey($format);

        return $key ? config("content.format_options.{$key}.choices") : null;
    }

    /** @return array{label: string, words?: int, count: array{0: int, 1: int}}|null */
    public static function choice(string $format, ?string $choice): ?array
    {
        return $choice === null ? null : (self::choices($format)[$choice] ?? null);
    }

    public static function optionLabel(string $format): ?string
    {
        $key = self::optionKey($format);

        return $key ? config("content.format_options.{$key}.label") : null;
    }

    public static function supportsFilming(string $format): bool
    {
        return (bool) (self::get($format)['filming'] ?? false);
    }

    /**
     * القالب المناسب للهدف والشكل.
     *
     * القالب الذي يطلب ما لا يملكه المتجر يُتخطى إلى افتراضي البنية:
     * «تعزيز السمعة» بلا تجربة عميل حقيقية يُكتب منشوراً عادياً، لا شهادة مخترعة.
     */
    public static function templateFor(string $goal, string $format, Brand $brand, ?Product $product): string
    {
        if ($fixed = self::get($format)['template'] ?? null) {
            return $fixed;
        }

        $rules = config('content.template_for.'.self::kind($format), []);
        $default = $rules['default'] ?? 'focused_post';
        $preferred = $rules['goals'][$goal] ?? $default;

        $requires = config("content.templates.{$preferred}.requires", []);

        return ContentRequirements::unmet($requires, $brand, $product) === null ? $preferred : $default;
    }

    /**
     * ما يحتاجه المتصفح لبناء النموذج بلا رحلة للخادم: الأشكال لكل منصة،
     * والحقل التابع لكل شكل، وتكلفة كل شكل.
     *
     * @return array<string, array>
     */
    public static function forBrowser(): array
    {
        $costs = config('credits.costs', []);

        return collect(config('content.formats'))->map(fn ($format, $key) => [
            'label' => $format['label'],
            'kind' => $format['kind'],
            'filming' => (bool) ($format['filming'] ?? false),
            'option' => ($optionKey = $format['option'] ?? null) ? [
                'label' => config("content.format_options.{$optionKey}.label"),
                // قائمة لا كائن: مفاتيح رقمية مثل «15» يعيد جافاسكربت ترتيبها
                'choices' => collect(config("content.format_options.{$optionKey}.choices"))
                    ->map(fn ($choice, $value) => ['value' => (string) $value, 'label' => $choice['label']])
                    ->values()
                    ->all(),
            ] : null,
            'cost' => (int) ($costs[ContentFormat::from($format['kind'])->creditOperation()] ?? 1),
        ])->all();
    }
}
