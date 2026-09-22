<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\AI\ProviderException;
use App\Services\AI\Support\JsonExtractor;
use Illuminate\Support\Facades\Http;

class AnthropicTextProvider implements TextProvider
{
    public function __construct(protected array $config) {}

    public function name(): string
    {
        return 'anthropic';
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
            $system .= "\n\n".$this->schemaInstruction($request->schema);
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->config['api_key'],
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout($this->config['timeout'] ?? 120)
            ->post(rtrim($this->config['base_url'], '/').'/messages', [
                'model' => $this->model(),
                'max_tokens' => min($request->maxTokens, $this->config['max_tokens'] ?? 4096),
                'temperature' => $request->temperature,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $request->prompt],
                ],
            ]);

        if ($response->failed()) {
            throw ProviderException::fromStatus($this->name(), $response->status(), $response->body(), $response->header('Retry-After'));
        }

        $json = $response->json();
        $raw = collect($json['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        return new TextResponse(
            raw: $raw,
            data: $request->schema ? JsonExtractor::extract($raw) : null,
            provider: $this->name(),
            model: $json['model'] ?? $this->model(),
            tokensIn: (int) data_get($json, 'usage.input_tokens', 0),
            tokensOut: (int) data_get($json, 'usage.output_tokens', 0),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    protected function schemaInstruction(array $schema): string
    {
        return "أعد الإجابة كائن JSON صالحاً فقط، بلا أي نص قبله أو بعده وبلا علامات تنسيق.\n"
            ."التزم بهذا المخطط حرفياً:\n".json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
