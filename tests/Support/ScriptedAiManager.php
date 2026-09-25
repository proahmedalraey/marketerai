<?php

namespace Tests\Support;

use App\Models\GenerationJob;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\AI\Support\JsonExtractor;
use App\Services\Settings\AiSettings;
use RuntimeException;

/**
 * نموذج «مكتوب مسبقاً» للاختبار: يسجّل ما نرسله، ويردّ بنصوص نحددها.
 *
 * الرد يمر بـ JsonExtractor كما يفعل المزود الحقيقي، فنختبر ما يحدث حين
 * يغلّف النموذج JSON بسياج أو يسبقه بجملة — لا بيانات جاهزة مثالية.
 */
class ScriptedAiManager extends AiManager
{
    /** @var array<int, TextRequest> */
    public array $requests = [];

    /** المزود والنموذج اللذان طُلبا مع كل طلب (null = الافتراضي). @var array<int, array{provider: ?string, model: ?string}> */
    public array $routes = [];

    /** @var array<int, string|\Throwable> */
    protected array $replies = [];

    public static function install(): self
    {
        $fake = new self(app(AiSettings::class));
        app()->instance(AiManager::class, $fake);

        return $fake;
    }

    public function replyWith(string|array|\Throwable ...$replies): self
    {
        foreach ($replies as $reply) {
            $this->replies[] = is_array($reply) ? json_encode($reply, JSON_UNESCAPED_UNICODE) : $reply;
        }

        return $this;
    }

    public function generateText(TextRequest $request, ?GenerationJob $job = null, ?string $provider = null, ?string $model = null): TextResponse
    {
        $this->requests[] = $request;
        $this->routes[] = ['provider' => $provider, 'model' => $model];

        $reply = array_shift($this->replies) ?? throw new RuntimeException('لا رد مجهّز لهذا الطلب.');

        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return new TextResponse(
            raw: $reply,
            data: $request->schema ? JsonExtractor::extract($reply) : null,
            provider: 'scripted',
            model: 'scripted-1',
            tokensIn: 0,
            tokensOut: 0,
            latencyMs: 0,
        );
    }

    public function lastRequest(): TextRequest
    {
        return end($this->requests) ?: throw new RuntimeException('لم يُرسل أي طلب.');
    }
}
