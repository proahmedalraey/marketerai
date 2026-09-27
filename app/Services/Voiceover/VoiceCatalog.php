<?php

namespace App\Services\Voiceover;

use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\File;

/**
 * قراءة config/voiceover.php: المذيعون والأنماط واللهجات والمستويات،
 * وتحويل اختيار التاجر إلى توجيه أداء للنموذج وتكلفة بالنقاط.
 */
class VoiceCatalog
{
    public function __construct(protected CreditService $credits) {}

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
            'avatar' => $this->avatarUrl($voice),
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
     * توجيه الأداء الذي يُرسل مع النص (لا يُنطق): الأسلوب ثم اللهجة.
     *
     * @param  array{style: string, custom_style?: ?string, language: string, dialect?: ?string, variant?: ?string, accent?: ?string}  $choice
     */
    public function direction(array $choice): string
    {
        $lines = [];

        if ($choice['style'] === 'custom') {
            $custom = trim((string) ($choice['custom_style'] ?? ''));

            if ($custom !== '') {
                $lines[] = "Style: follow the client's delivery notes exactly (written in Arabic): «{$custom}»";
            }
        } elseif ($direction = $this->styles()[$choice['style']]['direction'] ?? null) {
            $lines[] = "Style: {$direction}";
        }

        $lines[] = 'Accent: '.$this->accentDirection($choice);

        return implode("\n", $lines);
    }

    protected function accentDirection(array $choice): string
    {
        if (($choice['language'] ?? 'ar') === 'en') {
            $accent = $this->accents()[$choice['accent'] ?? ''] ?? $this->accents()[config('voiceover.default_accent')];

            return 'Speak in English. '.$accent['direction'];
        }

        $dialect = $this->dialects()[$choice['dialect'] ?? ''] ?? $this->dialects()['auto'];
        $variant = $dialect['variants'][$choice['variant'] ?? ''] ?? null;

        return 'Speak in Arabic. '.($variant['direction'] ?? $dialect['direction']);
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

    /** صورة المذيع إن وُضعت في public/، وإلا null فتظهر أيقونة الحرف. */
    protected function avatarUrl(array $voice): ?string
    {
        $path = $voice['avatar'] ?? null;

        return $path && File::exists(public_path($path)) ? asset($path) : null;
    }
}
