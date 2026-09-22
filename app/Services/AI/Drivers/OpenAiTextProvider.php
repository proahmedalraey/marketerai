<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\AI\ProviderException;
use App\Services\AI\Support\JsonExtractor;
use Illuminate\Support\Facades\Http;

class OpenAiTextProvider implements TextProvider
{
    public function __construct(protected array $config) {}

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->config['model'];
    }

    public function generate(TextRequest $request): TextResponse
    {
        $startedAt = microtime(true);

        $payload = [
            'model' => $this->model(),
            'temperature' => $request->temperature,
            'max_tokens' => min($request->maxTokens, $this->config['max_tokens'] ?? 4096),
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->prompt],
            ],
        ];

        if ($request->schema) {
            $payload['response_format'] = ['type' => 'json_object'];
            $payload['messages'][0]['content'] .= "\n\nأعد JSON صالحاً يطابق هذا المخطط حرفياً:\n"
                .json_encode($request->schema, JSON_UNESCAPED_UNICODE);
        }

        $response = Http::withToken($this->config['api_key'])
            ->timeout($this->config['timeout'] ?? 120)
            ->post(rtrim($this->config['base_url'], '/').'/chat/completions', $payload);

        if ($response->failed()) {
            throw ProviderException::fromStatus($this->name(), $response->status(), $response->body(), $response->header('Retry-After'));
        }

        $json = $response->json();
        $raw = (string) data_get($json, 'choices.0.message.content', '');

        return new TextResponse(
            raw: $raw,
            data: $request->schema ? JsonExtractor::extract($raw) : null,
            provider: $this->name(),
            model: $json['model'] ?? $this->model(),
            tokensIn: (int) data_get($json, 'usage.prompt_tokens', 0),
            tokensOut: (int) data_get($json, 'usage.completion_tokens', 0),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }
}
