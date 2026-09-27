<?php

namespace App\Services\Voiceover;

use App\Models\ContentItem;
use Illuminate\Support\Str;

/**
 * ما يُنطق من محتوى مكتوب.
 *
 * المنشور يُقرأ لا يُسمع: فيه هاشتاقات وروابط وإيموجي ونص شاشة. هنا نستخرج
 * الكلام وحده بترتيب قراءته — كلام المشاهد في سكربت الفيديو، ونص الشرائح في
 * الكاروسيل، والكابشن في المنشور — وننظّفه مما لا يُقال بصوت.
 */
class VoiceScript
{
    /** نص التعليق من محتوى في الخطة، منظّفاً ومقصوصاً عند الحد. */
    public static function fromContentItem(ContentItem $item): string
    {
        $blocks = match (true) {
            $item->scenes() !== [] => array_column($item->scenes(), 'voiceover'),
            filled($item->script()) => [$item->script()],
            $item->frames() !== [] => array_column($item->frames(), 'text'),
            $item->slides() !== [] => array_column($item->slides(), 'text'),
            $item->tweets() !== [] => $item->tweets(),
            $item->sections() !== [] => [
                $item->body['title'] ?? '',
                ...array_map(fn ($s) => trim(($s['heading'] ?? '').'. '.($s['text'] ?? ''), '. '), $item->sections()),
            ],
            default => [$item->caption ?? ''],
        };

        // الافتتاحية تسبق مشاهد الفيديو حين تُكتب منفصلة
        if (filled($hook = $item->body['hook'] ?? null) && $item->scenes() !== []) {
            array_unshift($blocks, $hook);
        }

        $text = static::clean(implode("\n", array_filter(array_map('strval', $blocks), 'filled')));

        return Str::limit($text, (int) config('voiceover.max_chars', 2000), '');
    }

    /** يحذف ما لا يُنطق: هاشتاقات وروابط وإيموجي ورموز تنسيق، ويوحّد الفراغات. */
    public static function clean(string $text): string
    {
        $text = preg_replace('~https?://\S+|www\.\S+~u', '', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])#[\p{L}\p{N}_]+/u', '', $text);
        // إيموجي ورموز تصويرية ومحدِّدات التنوع
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}]/u', '', $text);
        $text = preg_replace('/[*_`~>|]+/u', '', $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/ *\R */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    public static function words(string $text): int
    {
        // الوسوم [short pause] لا تُحسب كلاماً
        $text = preg_replace('/\[[^\]\n]{1,40}\]/u', ' ', $text);

        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    /** بطاقة «محتوى سابق»: ما يلزم الواجهة للبحث والتصفية والعرض. */
    public static function card(ContentItem $item): array
    {
        $text = static::fromContentItem($item);
        $firstLine = Str::of($text)->before("\n")->squish()->toString();
        $date = $item->planned_for ?? $item->created_at;

        return [
            'id' => $item->id,
            'platform' => $item->platform,
            'platform_label' => config("content.platforms.{$item->platform}.label", $item->platform),
            'dialect_label' => $item->dialectLabel() ?? ($item->language === 'en' ? 'English' : null),
            'goal_label' => config("voiceover.goal_tags.{$item->goal}") ?? config("content.goals.{$item->goal}.label"),
            'title' => Str::limit($firstLine, 70),
            'subtitle' => $item->product?->title ?? $item->variantLabel(),
            'date' => $date ? $date->day.' '.static::month($date->month) : '',
            'words' => static::words($text),
            'text' => $text,
            'language' => $item->language === 'en' ? 'en' : 'ar',
        ];
    }

    public static function month(int $month): string
    {
        return ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'][$month - 1] ?? '';
    }
}
