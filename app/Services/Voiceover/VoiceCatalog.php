<?php

namespace App\Services\Voiceover;

use App\Services\Credits\CreditService;
use App\Services\Settings\AiSettings;
use Illuminate\Support\Facades\File;

/**
 * قراءة config/voiceover.php: المذيعون والأنماط واللهجات والمستويات،
 * وتحويل اختيار التاجر إلى توجيه أداء للنموذج وتكلفة بالنقاط.
 */
class VoiceCatalog
{
    public function __construct(protected CreditService $credits, protected AiSettings $settings) {}

    /** @return array<string, array> */
    public function voices(): array
    {
        return config('voiceover.voices', []);
    }

    public function voice(string $key): ?array
    {
        return $this->voices()[$key] ?? null;
    }

    /** المذيعون للواجهة: بلا اسم الصوت عند المزود (تفصيل تقني لا يعني التاجر). */
    public function voiceCards(): array
    {
        return collect($this->voices())->map(fn (array $voice, string $key) => [
            'key' => $key,
            'name' => $voice['name'],
            'en' => $voice['en'] ?? $voice['name'],
            'gender' => $voice['gender'],
            'tone' => $voice['tone'] ?? '',
            'initial' => mb_substr($voice['name'], 0, 1),
            'avatar' => $this->avatarUrl($key, $voice),
        ])->values()->all();
    }

    public function styles(): array
    {
        return config('voiceover.styles', []);
    }

    public function dialects(): array
    {
        return config('voiceover.dialects', []);
    }

    public function accents(): array
    {
        return config('voiceover.accents', []);
    }

    public function tiers(): array
    {
        // نموذج كل مستوى قد يُضبط من صفحة الإعدادات: يُطبَّق قبل القراءة لا عند أول طلب للمزود
        $this->settings->apply();

        return config('voiceover.tiers', []);
    }

    public function tier(string $key): array
    {
        return $this->tiers()[$key] ?? $this->tiers()[config('voiceover.default_tier')];
    }

    /** دقائق تُحجز لنص: كلمات ÷ سرعة كلام متحفظة، دقيقة واحدة على الأقل. */
    public function estimateMinutes(string $text): int
    {
        $words = VoiceScript::words($text);

        return max(1, (int) ceil($words / max(1, (int) config('voiceover.words_per_minute', 120))));
    }

    /** مدة تقريبية لنطق النص: الكلمات بسرعة الكلام المقيسة، وكل وسم أداء يضيف وقفة قصيرة. */
    public function expectedSeconds(string $text): float
    {
        $tags = preg_match_all('/\[[^\]\n]{1,40}\]/u', $text);

        return VoiceScript::words($text) / max(0.5, (float) config('voiceover.overlong.words_per_second', 2.0)) + $tags * 0.8;
    }

    /**
     * تسجيل أطول بكثير من نصه: النموذج قرأ التوجيه أو كرر النص.
     * الأسلوب المخصص مستثنى — «ببطء شديد» طويل بحق (همس بطيء بلغ ثلاثة أضعاف المعتاد في المراجعة).
     */
    public function looksOverlong(string $text, float $seconds, ?string $style = null): bool
    {
        if ($style === 'custom') {
            return false;
        }

        return $seconds > $this->expectedSeconds($text) * (float) config('voiceover.overlong.factor', 1.8)
            + (float) config('voiceover.overlong.grace_seconds', 3);
    }

    /** الدقائق المحتسبة من المدة الفعلية: كل دقيقة بدأت تُحسب. */
    public function billedMinutes(float $seconds): int
    {
        return max(1, (int) ceil(round($seconds, 1) / 60));
    }

    public function costOperation(string $tier): string
    {
        return $this->tier($tier)['credits'] ?? 'voice.minute';
    }

    public function estimatedCost(string $text, string $tier): float
    {
        return $this->credits->cost($this->costOperation($tier), $this->estimateMinutes($text));
    }

    /** سعر الدقيقة لكل مستوى، لتحسب الواجهة التقدير وهي تكتب. */
    public function minuteCosts(): array
    {
        return collect($this->tiers())->map(fn (array $tier) => $this->credits->cost($tier['credits']))->all();
    }

    /**
     * توجيه الأداء (لا يُنطق): عبارة واحدة، اللهجة ثم الأسلوب، تُكمل «Read aloud …:» عند المزود.
     *
     * الأسلوب المخصص يُرسل مترجماً (custom_style_en) إن وُجد: ملاحظات التاجر العربية تجاهلها
     * gemini-3.8-flash-tts في مراجعة الأنماط، والمترجمة التزم بها. بلا ترجمة تُرسل العربية كما هي.
     *
     * @param  array{style: string, custom_style?: ?string, custom_style_en?: ?string, language: string, dialect?: ?string, variant?: ?string, accent?: ?string}  $choice
     */
    public function direction(array $choice): string
    {
        $parts = [$this->accentDirection($choice)];

        if ($choice['style'] === 'custom') {
            $english = trim((string) ($choice['custom_style_en'] ?? ''));
            $arabic = trim((string) ($choice['custom_style'] ?? ''));

            $parts[] = $english !== '' ? $english : ($arabic !== '' ? "following these delivery notes written in Arabic «{$arabic}»" : null);
        } else {
            $parts[] = $this->styles()[$choice['style']]['direction'] ?? null;
        }

        return implode(', ', array_filter($parts));
    }

    public function accentDirection(array $choice): string
    {
        if (($choice['language'] ?? 'ar') === 'en') {
            $accent = $this->accents()[$choice['accent'] ?? ''] ?? $this->accents()[config('voiceover.default_accent')];

            return $accent['direction'];
        }

        $dialect = $this->dialects()[$choice['dialect'] ?? ''] ?? $this->dialects()['auto'];
        $variant = $dialect['variants'][$choice['variant'] ?? ''] ?? null;

        return $variant['direction'] ?? $dialect['direction'];
    }

    /** «سعودية بيضاء»، «مصرية»، «English — British». */
    public function languageLabel(array $choice): string
    {
        if (($choice['language'] ?? 'ar') === 'en') {
            $accent = $this->accents()[$choice['accent'] ?? ''] ?? null;

            return trim('English'.($accent ? ' — '.$accent['en'] : ''));
        }

        $dialect = $this->dialects()[$choice['dialect'] ?? ''] ?? null;

        if (! $dialect) {
            return 'تلقائي';
        }

        $variant = $dialect['variants'][$choice['variant'] ?? ''] ?? null;

        return trim($dialect['label'].($variant ? ' '.$variant['label'] : ''));
    }

    public function styleLabel(string $style): string
    {
        return $this->styles()[$style]['label'] ?? $style;
    }

    /**
     * صورة المذيع بالأولوية: avatar صريح في الإعداد، ثم صورة فوتوغرافية (voiceover:avatars → <key>.jpg)،
     * ثم الرسم المرفق مع الكود (<key>.svg). بلا أيٍّ منها = null فيظهر الحرف الأول.
     * بصمة وقت الملف تكسر الكاش حين تُستبدل الصورة.
     */
    protected function avatarUrl(string $key, array $voice): ?string
    {
        $candidates = array_filter([$voice['avatar'] ?? null, "images/voices/{$key}.jpg", "images/voices/{$key}.svg"]);

        foreach ($candidates as $path) {
            if (File::exists($file = public_path($path))) {
                return asset($path).'?v='.File::lastModified($file);
            }
        }

        return null;
    }
}
