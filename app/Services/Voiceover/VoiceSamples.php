<?php

namespace App\Services\Voiceover;

use App\Jobs\GenerateVoiceSampleJob;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\SpeechRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * عينة «استمع» لكل مذيع: جملة تعريف قصيرة تُولَّد مرة لكل صوت ولغة وتُحفظ لكل المتاجر.
 *
 * بلا نقاط: هي تجربة قبل الشراء لا إنتاج، وتُدفع مرة واحدة على مستوى المنصة.
 * أول طلب لعينة غير موجودة يضعها في الطابور (لا نموذج داخل طلب HTTP)، والواجهة
 * تستطلع حتى تجهز. `php artisan voiceover:samples` يولّدها كلها مسبقاً.
 */
class VoiceSamples
{
    public function __construct(protected AiManager $ai, protected VoiceCatalog $catalog) {}

    public function path(string $voice, string $language): string
    {
        return "voice-samples/{$voice}-{$language}.wav";
    }

    public function url(string $voice, string $language): ?string
    {
        $disk = Storage::disk(config('ai.media_disk'));
        $path = $this->path($voice, $language);

        return $disk->exists($path) ? $disk->url($path) : null;
    }

    /** الرابط إن وُجدت، وإلا تُوضع في الطابور مرة واحدة ويعود null (الواجهة تعيد السؤال). */
    public function request(string $voice, string $language): ?string
    {
        if ($url = $this->url($voice, $language)) {
            return $url;
        }

        // طلبات متتالية للعينة نفسها أثناء تجهيزها لا تضع مهمة ثانية
        if (Cache::add($this->pendingKey($voice, $language), true, 120)) {
            GenerateVoiceSampleJob::dispatch($voice, $language)->onQueue('media');
        }

        return null;
    }

    public function generate(string $voice, string $language): string
    {
        $config = $this->catalog->voice($voice) ?? throw new \InvalidArgumentException("مذيع غير معروف: {$voice}");
        $tier = $this->catalog->tier(config('voiceover.samples.tier', 'standard'));
        $name = $language === 'en' ? ($config['en'] ?? $config['name']) : $config['name'];

        $response = $this->ai->generateSpeech(new SpeechRequest(
            transcript: str_replace(':name', $name, (string) config("voiceover.samples.{$language}")),
            voice: $config['provider_voice'],
            direction: 'Style: '.config('voiceover.samples.direction')."\nAccent: "
                .($language === 'en' ? 'Speak in English. General American English accent.' : 'Speak in Arabic. Neutral "white" Saudi/Gulf Arabic.'),
            model: $tier['model'] ?? null,
            operation: 'voice.sample',
        ));

        $path = $this->path($voice, $language);
        Storage::disk(config('ai.media_disk'))->put($path, $response->audio);
        Cache::forget($this->pendingKey($voice, $language));

        return $path;
    }

    public function forgetPending(string $voice, string $language): void
    {
        Cache::forget($this->pendingKey($voice, $language));
    }

    protected function pendingKey(string $voice, string $language): string
    {
        return "voice-sample.pending.{$voice}.{$language}";
    }
}
