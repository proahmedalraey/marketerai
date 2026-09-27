<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\SpeechProvider;
use App\Services\AI\Drivers\Concerns\WithModelOverride;
use App\Services\AI\DTO\SpeechRequest;
use App\Services\AI\DTO\SpeechResponse;
use App\Services\AI\ProviderException;
use App\Services\AI\Support\Wav;
use Illuminate\Support\Facades\Http;

/**
 * النطق بنماذج Gemini TTS (generateContent بمخرج صوتي).
 *
 * التوجيه والنص في رسالة واحدة: النموذج يقرأ «ملاحظات المخرج» ويؤدي ما تحت
 * TRANSCRIPT وحده. جُرّب على gemini-3.8-flash-tts: لا ينطق الملاحظات ولا وسوم [short pause].
 */
class GeminiSpeechProvider implements SpeechProvider
{
    use WithModelOverride;

    public function __construct(protected array $config)
    {
        // model في إعداد المزود هو نموذج النص؛ النطق نموذجه speech_model (وwithModel يبدّله لكل طلب)
        $this->config['model'] = $config['speech_model'] ?? 'gemini-3.8-flash-tts';
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->config['model'];
    }

    public function synthesize(SpeechRequest $request): SpeechResponse
    {
        $startedAt = microtime(true);

        $response = Http::withHeaders(['x-goog-api-key' => $this->config['api_key']])
            ->timeout($this->config['speech_timeout'] ?? 240)
            ->post(rtrim($this->config['base_url'], '/')."/models/{$this->model()}:generateContent", [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => static::prompt($request)]]],
                ],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => [
                        'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $request->voice]],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw ProviderException::fromStatus($this->name(), $response->status(), $response->body(), $response->header('Retry-After'));
        }

        $json = $response->json();

        if ($blocked = data_get($json, 'promptFeedback.blockReason')) {
            throw new ProviderException("رفض Gemini الطلب ({$blocked}).", $this->name(), 200,
                display: 'رفض مزود الصوت هذا النص. عدّل الصياغة وجرّب مجدداً — أُرجعت نقاطك.');
        }

        $inline = collect(data_get($json, 'candidates.0.content.parts', []))
            ->pluck('inlineData')
            ->first(fn ($data) => filled($data['data'] ?? null));

        if (! $inline) {
            throw new ProviderException(
                'لم يُعد Gemini صوتاً (finishReason: '.data_get($json, 'candidates.0.finishReason', '?').')',
                $this->name(),
                200,
                display: 'لم يُعد مزود الصوت تسجيلاً. أُرجعت نقاطك — جرّب مجدداً.',
            );
        }

        $audio = Wav::normalize((string) base64_decode($inline['data']), $inline['mimeType'] ?? null);

        return new SpeechResponse(
            audio: $audio,
            durationSeconds: Wav::duration($audio),
            provider: $this->name(),
            model: $json['modelVersion'] ?? $this->model(),
            tokensIn: (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            tokensOut: (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /** ملاحظات الأداء ثم النص. التوجيه الفارغ = قراءة طبيعية. */
    public static function prompt(SpeechRequest $request): string
    {
        $notes = trim($request->direction);

        return 'You are a professional voice actor recording a voice-over. '
            .'Perform ONLY the text under TRANSCRIPT, word for word. '
            .'Never read these notes, headings or bracketed tags aloud; bracketed tags are performance cues.'
            .($notes !== '' ? "\n\nDIRECTOR'S NOTES:\n{$notes}" : '')
            ."\n\nTRANSCRIPT:\n".trim($request->transcript);
    }
}
