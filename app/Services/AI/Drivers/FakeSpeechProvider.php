<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\SpeechProvider;
use App\Services\AI\DTO\SpeechRequest;
use App\Services\AI\DTO\SpeechResponse;
use App\Services\AI\Support\Wav;

/**
 * نطق وهمي للاختبارات: نغمة هادئة بطول يقارب قراءة النص، في ملف WAV حقيقي
 * يشغّله المتصفح — حتى يُختبر المشغّل والسجل بلا مفتاح ولا تكلفة.
 */
class FakeSpeechProvider implements SpeechProvider
{
    protected ?string $model = null;

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return $this->model ?? 'fake-tts';
    }

    public function withModel(string $model): static
    {
        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    public function synthesize(SpeechRequest $request): SpeechResponse
    {
        $words = max(1, count(preg_split('/\s+/u', trim($request->transcript), -1, PREG_SPLIT_NO_EMPTY)));
        // ~2.5 كلمة في الثانية، بين ثانية و3 دقائق
        $seconds = min(180, max(1, round($words / 2.5, 1)));

        $rate = 8000;
        $samples = (int) ($seconds * $rate);
        $pcm = '';

        for ($i = 0; $i < $samples; $i++) {
            // 220Hz بسعة منخفضة وتلاشٍ في آخر عُشر ثانية
            $fade = min(1, ($samples - $i) / ($rate / 10));
            $pcm .= pack('v', (int) (sin(2 * M_PI * 220 * $i / $rate) * 1800 * $fade) & 0xFFFF);
        }

        $audio = Wav::fromPcm($pcm, $rate);

        return new SpeechResponse(
            audio: $audio,
            durationSeconds: Wav::duration($audio),
            provider: $this->name(),
            model: $this->model(),
        );
    }
}
