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
 * التوجيه جملة قصيرة واحدة تنتهي بنقطتين ثم النص («Read aloud …:» ثم سطر جديد) — صيغة Google الموثّقة.
 * لا عناوين ولا «لا تنطق هذه التعليمات»: gemini-3.8-flash-lite-tts كان يقرأ كتلة
 * «DIRECTOR'S NOTES / TRANSCRIPT» بصوت عالٍ قبل النص (رُصد 2026-09-29 في عينات «استمع»)،
 * ولا يقبل systemInstruction (HTTP 400). بهذه الصيغة ينطق النص وحده، ووسوم [short pause] لا تُنطق.
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

        $finish = data_get($json, 'candidates.0.finishReason');

        // الحجب قرار لا عطل عابر: الإعادة تُرفض مثله، فالتاجر يعدّل النص أو الأسلوب
        if (($blocked = data_get($json, 'promptFeedback.blockReason')) || in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST'], true)) {
            throw new ProviderException('رفض Gemini الطلب ('.($blocked ?: $finish).').', $this->name(), 200,
                display: 'رفض مزود الصوت هذا الطلب. عدّل النص أو أسلوب الإلقاء وجرّب مجدداً — أُرجعت نقاطك.');
        }

        $inline = collect(data_get($json, 'candidates.0.content.parts', []))
            ->pluck('inlineData')
            ->first(fn ($data) => filled($data['data'] ?? null));

        // نماذج النطق تعيد أحياناً نصاً أو رداً فارغاً بدل الصوت (موثّق عند Google، ورأيناه مع
        // gemini-3.1-flash-tts-preview): عابر، والإعادة تنجح غالباً — فتمر بإعادة AiManager
        if (! $inline) {
            throw new ProviderException(
                'لم يُعد Gemini صوتاً (finishReason: '.data_get($json, 'candidates.0.finishReason', '?').')',
                $this->name(),
                200,
                retryable: true,
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

    /** «Read aloud <التوجيه>:» ثم النص. نقطتان داخل التوجيه تُقرأ حدّاً للنص، فتصير فاصلة. */
    public static function prompt(SpeechRequest $request): string
    {
        $direction = trim(str_replace(':', ',', (string) preg_replace('/\s+/u', ' ', $request->direction)), ' ,.');

        return 'Read aloud'.($direction !== '' ? " {$direction}" : ' naturally').":\n".trim($request->transcript);
    }
}
