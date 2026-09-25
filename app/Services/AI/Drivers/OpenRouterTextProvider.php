<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\Drivers\Concerns\WithModelOverride;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\AI\ProviderException;
use App\Services\AI\Support\JsonExtractor;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * نصوص عبر OpenRouter: واجهة متوافقة مع OpenAI لكن بمئات النماذج خلف مفتاح واحد.
 *
 * يختلف عن درايفر OpenAI في ثلاثة أمور: يرسل ترويسات التعريف التي يطلبها OpenRouter،
 * ويقرأ التكلفة الفعلية من الرد (usage.cost) فيسجّلها بدل التقدير، ويتعامل مع الأخطاء
 * التي تعود بحالة 200 لأن المزود الخلفي فشل بعد بدء الرد.
 */
class OpenRouterTextProvider implements TextProvider
{
    use WithModelOverride;

    public function __construct(protected array $config) {}

    public function name(): string
    {
        return 'openrouter';
    }

    public function model(): string
    {
        return $this->config['model'];
    }

    public function generate(TextRequest $request): TextResponse
    {
        $startedAt = microtime(true);

        $system = $request->system;

        if ($request->schema) {
            $system .= "\n\nأعد JSON صالحاً فقط، بلا أي نص قبله أو بعده وبلا علامات تنسيق، يطابق هذا المخطط حرفياً:\n"
                .json_encode($request->schema, JSON_UNESCAPED_UNICODE);
        }

        $payload = [
            'model' => $this->model(),
            'temperature' => $request->temperature,
            // لا نقصّه إلى maxTokens الطلب: نماذج التفكير تستهلك منه قبل أن تكتب
            'max_tokens' => max($request->maxTokens, $this->config['max_tokens'] ?? 16384),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $request->prompt],
            ],
        ];

        if ($request->schema) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        if (in_array($this->config['reasoning'] ?? 'low', ['low', 'high'], true)) {
            $payload['reasoning'] = ['effort' => $this->config['reasoning'] ?? 'low'];
        }

        $response = $this->send($payload);

        // خيار لا يدعمه النموذج المختار: نعيد بلا ذلك الخيار بدل إسقاط التوليد كله
        foreach (['reasoning', 'response_format'] as $option) {
            if ($response->status() === 400 && isset($payload[$option])
                && str_contains(strtolower($response->body()), $option === 'reasoning' ? 'reasoning' : 'response_format')) {
                Log::warning("OpenRouter رفض {$option}؛ إعادة بلا هذا الخيار", [
                    'model' => $this->model(),
                    'error' => mb_substr($response->body(), 0, 500),
                ]);

                unset($payload[$option]);
                $response = $this->send($payload);
            }
        }

        if ($response->failed()) {
            throw ProviderException::fromStatus($this->name(), $response->status(), $response->body(), $response->header('Retry-After'));
        }

        $json = $response->json() ?? [];

        // حالة 200 وبداخلها خطأ: المزود الخلفي سقط بعد أن قبل OpenRouter الطلب
        if ($error = data_get($json, 'error')) {
            $code = (int) data_get($error, 'code', 502);

            throw ProviderException::fromStatus(
                $this->name(),
                $code >= 400 && $code < 600 ? $code : 502,
                json_encode(['error' => $error], JSON_UNESCAPED_UNICODE),
            );
        }

        $raw = (string) data_get($json, 'choices.0.message.content', '');
        $data = $request->schema ? JsonExtractor::extract($raw) : null;

        if (data_get($json, 'choices.0.finish_reason') === 'length' && ($request->schema ? $data === null : $raw === '')) {
            Log::warning('انقطع رد OpenRouter عند حد الطول', [
                'model' => $this->model(),
                'operation' => $request->operation,
                'reasoning' => data_get($json, 'usage.completion_tokens_details.reasoning_tokens'),
                'output' => data_get($json, 'usage.completion_tokens'),
            ]);

            throw new ProviderException(
                'انقطع رد OpenRouter عند حد الطول (length)',
                $this->name(),
                200,
                display: 'انقطع رد الذكاء الاصطناعي قبل أن يكتمل. أُرجعت نقاطك — جرّب مجدداً.',
            );
        }

        return new TextResponse(
            raw: $raw,
            data: $data,
            provider: $this->name(),
            model: $json['model'] ?? $this->model(),
            tokensIn: (int) data_get($json, 'usage.prompt_tokens', 0),
            tokensOut: (int) data_get($json, 'usage.completion_tokens', 0),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            costUsd: (float) data_get($json, 'usage.cost', 0),
        );
    }

    protected function send(array $payload)
    {
        return $this->client()->post(rtrim($this->config['base_url'], '/').'/chat/completions', $payload);
    }

    protected function client(): PendingRequest
    {
        return Http::withToken($this->config['api_key'])
            ->withHeaders(static::identityHeaders())
            ->timeout($this->config['timeout'] ?? 180);
    }

    /** يظهر التطبيق بهذا الاسم في لوحة OpenRouter وسجل الاستهلاك */
    public static function identityHeaders(): array
    {
        return [
            'HTTP-Referer' => (string) config('app.url'),
            'X-OpenRouter-Title' => (string) config('app.name'),
        ];
    }
}
