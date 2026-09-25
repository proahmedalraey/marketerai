<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\Drivers\Concerns\WithModelOverride;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\AI\ProviderException;
use App\Services\AI\Support\JsonExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiTextProvider implements TextProvider
{
    use WithModelOverride;

    public function __construct(protected array $config) {}

    public function name(): string
    {
        return 'gemini';
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
            $system .= "\n\nأعد JSON صالحاً فقط يطابق هذا المخطط حرفياً:\n"
                .json_encode($request->schema, JSON_UNESCAPED_UNICODE);
        }

        $generationConfig = [
            'temperature' => $request->temperature,
            // لا نقصّه إلى maxTokens الطلب: نماذج 2.5 وما بعدها «تفكّر» من نفس السقف،
            // وسقف صغير يعيد ردّاً فارغاً مقطوعاً (finishReason = MAX_TOKENS)
            'maxOutputTokens' => max($request->maxTokens, $this->config['max_tokens'] ?? 8192),
        ];

        if ($request->schema) {
            $generationConfig['responseMimeType'] = 'application/json';

            // المخطط كنص في التعليمات «طلب»؛ responseSchema «قيد» يلتزم به النموذج
            // في الأنواع والحقول المطلوبة — فتقل ردود JSON المكسورة والأنواع الخاطئة.
            $generationConfig['responseSchema'] = $this->toGeminiSchema($request->schema);
        }

        if ($thinking = $this->thinkingConfig()) {
            $generationConfig['thinkingConfig'] = $thinking;
        }

        $send = fn (array $config) => Http::withHeaders(['x-goog-api-key' => $this->config['api_key']])
            ->timeout($this->config['timeout'] ?? 120)
            ->post(rtrim($this->config['base_url'], '/')."/models/{$this->model()}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $request->prompt]]],
                ],
                'generationConfig' => $config,
            ]);

        $response = $send($generationConfig);

        // نموذج لا يقبل ضبط التفكير (أو يقبل صيغة أخرى منه): نعيد مرة بلا ضبط
        if ($response->status() === 400 && isset($generationConfig['thinkingConfig'])
            && str_contains(strtolower($response->body()), 'thinking')) {
            Log::warning('Gemini رفض thinkingConfig؛ إعادة بلا ضبط التفكير', [
                'model' => $this->model(),
                'error' => mb_substr($response->body(), 0, 500),
            ]);

            unset($generationConfig['thinkingConfig']);
            $response = $send($generationConfig);
        }

        // شبكة أمان: إن رفض نموذجٌ ما المخطط، لا نُسقط التوليد كله —
        // نعيد مرة بلا قيد (كما كان السلوك قبله) ونسجّل ذلك لنعرف.
        if ($response->status() === 400 && isset($generationConfig['responseSchema'])
            && str_contains(strtolower($response->body()), 'schema')) {
            Log::warning('Gemini رفض responseSchema؛ إعادة بلا قيد', [
                'model' => $this->model(),
                'error' => mb_substr($response->body(), 0, 500),
            ]);

            unset($generationConfig['responseSchema']);
            $response = $send($generationConfig);
        }

        if ($response->failed()) {
            throw ProviderException::fromStatus($this->name(), $response->status(), $response->body(), $response->header('Retry-After'));
        }

        $json = $response->json();

        if ($blocked = data_get($json, 'promptFeedback.blockReason')) {
            throw new ProviderException("رفض Gemini الطلب ({$blocked}).", $this->name(), 200);
        }

        $raw = collect(data_get($json, 'candidates.0.content.parts', []))
            ->reject(fn ($part) => $part['thought'] ?? false)
            ->pluck('text')
            ->filter()
            ->implode("\n");

        $data = $request->schema ? JsonExtractor::extract($raw) : null;

        /*
         * رد مقطوع عند حد الطول: التفكير يُحسب من نفس السقف، فقد يستهلكه كله
         * قبل أن يكتب. كان يمر كـ«مخرج غير صالح» بلا سبب؛ الآن يُسمّى ويُسجَّل.
         */
        if (data_get($json, 'candidates.0.finishReason') === 'MAX_TOKENS' && ($request->schema ? $data === null : $raw === '')) {
            Log::warning('انقطع رد Gemini عند حد الطول', [
                'model' => $this->model(),
                'operation' => $request->operation,
                'thoughts' => data_get($json, 'usageMetadata.thoughtsTokenCount'),
                'output' => data_get($json, 'usageMetadata.candidatesTokenCount'),
            ]);

            throw new ProviderException(
                'انقطع رد Gemini عند حد الطول (MAX_TOKENS)',
                $this->name(),
                200,
                display: 'انقطع رد الذكاء الاصطناعي قبل أن يكتمل. أُرجعت نقاطك — جرّب مجدداً.',
            );
        }

        return new TextResponse(
            raw: $raw,
            data: $data,
            provider: $this->name(),
            model: $json['modelVersion'] ?? $this->model(),
            tokensIn: (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
            tokensOut: (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0)
                + (int) data_get($json, 'usageMetadata.thoughtsTokenCount', 0),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /**
     * ضبط التفكير: نماذج 2.x تقبل ميزانية بالتوكنز، ونماذج 3 وما بعدها مستوى.
     * off = لا نرسل شيئاً ويقرر النموذج بنفسه (السلوك السابق).
     */
    protected function thinkingConfig(): ?array
    {
        $level = $this->config['thinking'] ?? 'low';

        if (! in_array($level, ['low', 'high'], true)) {
            return null;
        }

        return str_starts_with($this->model(), 'gemini-2.')
            ? ['thinkingBudget' => $level === 'low' ? 1024 : -1]
            : ['thinkingLevel' => $level];
    }

    /** مفاتيح يقبلها مخطط Gemini (مجموعة OpenAPI فرعية). ما عداها يُسقط بدل أن يُرفض الطلب. */
    protected const SCHEMA_KEYS = ['type', 'format', 'description', 'nullable', 'enum', 'minItems', 'maxItems', 'properties', 'required', 'items'];

    /**
     * JSON Schema ← مخطط Gemini: الأنواع بأحرف كبيرة، والمفاتيح غير المدعومة تُحذف.
     */
    protected function toGeminiSchema(array $schema): array
    {
        $out = [];

        foreach (self::SCHEMA_KEYS as $key) {
            if (! array_key_exists($key, $schema)) {
                continue;
            }

            $out[$key] = match ($key) {
                'type' => strtoupper((string) $schema['type']),
                'properties' => array_map(fn ($prop) => $this->toGeminiSchema((array) $prop), $schema['properties']),
                'items' => $this->toGeminiSchema((array) $schema['items']),
                default => $schema[$key],
            };
        }

        return $out;
    }
}
