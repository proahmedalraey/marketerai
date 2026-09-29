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

    /**
     * @param  ?string  $model  نموذج بعينه بدل نموذج مستوى العينات (voiceover:samples --model):
     *                          الحصة المجانية 10 طلبات يومياً لكل نموذج، فتُوزَّع العينات عليها.
     */
    public function generate(string $voice, string $language, ?string $model = null): string
    {
        $config = $this->catalog->voice($voice) ?? throw new \InvalidArgumentException("مذيع غير معروف: {$voice}");
        $tier = $this->catalog->tier(config('voiceover.samples.tier', 'standard'));
        $name = $language === 'en' ? ($config['en'] ?? $config['name']) : $config['name'];

        // العينة تعرض طابع المذيع نفسه: «in … Arabic, informative and composed, as a friendly self-introduction»
        $direction = implode(', ', array_filter([
            $this->catalog->accentDirection($language === 'en'
                ? ['language' => 'en', 'accent' => 'american']
                : ['language' => 'ar', 'dialect' => 'saudi', 'variant' => 'white']),
            $config['character'] ?? null,
            config('voiceover.samples.direction'),
        ]));

        $request = new SpeechRequest(
            transcript: str_replace(':name', $name, (string) config("voiceover.samples.{$language}")),
            voice: $config['provider_voice'],
            direction: $direction,
            model: $model ?? $tier['model'] ?? null,
            operation: 'voice.sample',
        );

        $response = $this->ai->generateSpeech($request);

        // عينة قرأ فيها النموذج التوجيه أو كرر الجملة أسوأ من لا عينة: محاولة ثانية ثم رفض
        if ($this->catalog->looksOverlong($request->transcript, $response->durationSeconds)) {
            $response = $this->ai->generateSpeech($request);

            if ($this->catalog->looksOverlong($request->transcript, $response->durationSeconds)) {
                throw new \RuntimeException("عينة {$voice} أطول من جملتها مرتين ({$response->durationSeconds} ث)");
            }
        }

        $path = $this->path($voice, $language);
        Storage::disk(config('ai.media_disk'))->put($path, $response->audio);
        Cache::forget($this->pendingKey($voice, $language));

        return $path;
    }

    public function forgetPending(string $voice, string $language): void
    {
        Cache::forget($this->pendingKey($voice, $language));
    }

    /** سبب فشل آخر محاولة: تعرضه الواجهة فوراً بدل انتظار عينة لن تصل. يُقرأ مرة ثم يُمسح. */
    public function markFailed(string $voice, string $language, string $reason): void
    {
        Cache::put($this->failedKey($voice, $language), $reason, 600);
    }

    public function pullFailure(string $voice, string $language): ?string
    {
        return Cache::pull($this->failedKey($voice, $language));
    }

    protected function failedKey(string $voice, string $language): string
    {
        return "voice-sample.failed.{$voice}.{$language}";
    }

    protected function pendingKey(string $voice, string $language): string
    {
        return "voice-sample.pending.{$voice}.{$language}";
    }
}
