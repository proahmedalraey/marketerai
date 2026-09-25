<?php

namespace App\Services\AI;

use App\Models\AiUsageLog;
use App\Models\GenerationJob;
use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\ImageResponse;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\AI\Drivers\AnthropicTextProvider;
use App\Services\AI\Drivers\FakeImageProvider;
use App\Services\AI\Drivers\FakeTextProvider;
use App\Services\AI\Drivers\GeminiImageProvider;
use App\Services\AI\Drivers\GeminiTextProvider;
use App\Services\AI\Drivers\OpenAiImageProvider;
use App\Services\AI\Drivers\OpenAiTextProvider;
use App\Services\Settings\AiSettings;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * البوابة الوحيدة للنماذج.
 *
 * لا يستدعي أي كود في التطبيق مزوداً مباشرة. هذا ما يجعل تبديل مزود
 * رفع أسعاره أو أوقف نموذجاً تغييراً في ملف بيئة لا في الكود.
 * كما أنه المكان الوحيد الذي تُسجَّل فيه التكلفة الحقيقية.
 */
class AiManager
{
    protected array $resolved = [];

    protected ?string $settingsVersion = null;

    public function __construct(protected AiSettings $settings) {}

    /**
     * @param  string|null  $model  نموذج بعينه بدل نموذج المزود المضبوط (توجيه عملية بعينها)
     */
    public function text(?string $provider = null, ?string $model = null): TextProvider
    {
        $this->syncSettings();

        $name = $provider ?? config('ai.text_provider');
        $key = 'text.'.$name.($model ? "@{$model}" : '');

        return $this->resolved[$key] ??= $this->makeTextProvider($name, $model);
    }

    /**
     * هل يستطيع هذا المزود التوليد الآن؟ بعد تطبيق إعدادات الواجهة،
     * لأن المفتاح قد يكون محفوظاً فيها لا في ملف البيئة.
     */
    public function ready(string $provider): bool
    {
        $this->syncSettings();

        $config = config("ai.providers.{$provider}");

        return is_array($config) && (($config['driver'] ?? null) === 'fake' || filled($config['api_key'] ?? null));
    }

    public function image(?string $provider = null): ImageProvider
    {
        $this->syncSettings();

        $key = 'image.'.($provider ?? config('ai.image_provider'));

        return $this->resolved[$key] ??= $this->makeImageProvider($provider ?? config('ai.image_provider'));
    }

    /**
     * توليد نص مع إعادة محاولة للأخطاء المؤقتة وتسجيل الاستهلاك.
     */
    public function generateText(TextRequest $request, ?GenerationJob $job = null, ?string $provider = null, ?string $model = null): TextResponse
    {
        $driver = $this->text($provider, $model);

        $response = $this->withRetries(
            fn () => $driver->generate($request),
            $driver->name(),
            $request->operation
        );

        $this->logUsage($job, $driver->name(), $response->model, $request->operation, [
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
            'latency_ms' => $response->latencyMs,
            'cost_usd' => $response->costUsd,
        ]);

        return $response;
    }

    public function generateImage(ImageRequest $request, ?GenerationJob $job = null, ?string $provider = null): ImageResponse
    {
        $driver = $this->image($provider);

        $response = $this->withRetries(
            fn () => $driver->generate($request),
            $driver->name(),
            $request->operation
        );

        $this->logUsage($job, $driver->name(), $response->model, $request->operation, [
            'images' => $response->count(),
            'latency_ms' => $response->latencyMs,
            'cost_usd' => $response->costUsd,
        ]);

        return $response;
    }

    /**
     * يطبّق إعدادات الواجهة، ويُسقط المزودين المبنيين إن تغيّرت
     * حتى لا يبقى مزود محتفظاً بمفتاح أو نموذج سابق.
     */
    protected function syncSettings(): void
    {
        $version = $this->settings->apply();

        if ($version !== $this->settingsVersion) {
            $this->resolved = [];
            $this->settingsVersion = $version;
        }
    }

    protected function withRetries(callable $callback, string $provider, string $operation): mixed
    {
        $attempts = (int) config('ai.retry.times', 2) + 1;
        $sleep = (int) config('ai.retry.sleep_ms', 1500);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $callback();
            } catch (ProviderException $e) {
                $isLast = $attempt === $attempts;

                Log::warning('فشل استدعاء مزود ذكاء اصطناعي', [
                    'provider' => $provider,
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'retryable' => $e->retryable,
                    'quota_exhausted' => $e->quotaExhausted,
                    'message' => $e->getMessage(),
                ]);

                if ($isLast || ! $e->retryable) {
                    throw $e;
                }

                // ما يطلبه المزود صراحة يعلو على تقديرنا: المحاولة قبله مرفوضة سلفاً
                $waitMs = max($sleep * $attempt, (int) ($e->retryAfterSeconds ?? 0) * 1000);

                usleep($waitMs * 1000);
            }
        }

        throw new ProviderException('تعذر إكمال الطلب بعد كل المحاولات.', $provider);
    }

    protected function logUsage(?GenerationJob $job, string $provider, string $model, string $operation, array $metrics): void
    {
        try {
            AiUsageLog::create(array_merge([
                'brand_id' => $job?->brand_id,
                'generation_job_id' => $job?->id,
                'provider' => $provider,
                'model' => $model,
                'operation' => $operation,
                'succeeded' => true,
            ], $metrics));
        } catch (\Throwable $e) {
            // تسجيل الاستهلاك لا يجب أن يُسقط مهمة ناجحة
            Log::warning('تعذر تسجيل استهلاك النموذج: '.$e->getMessage());
        }
    }

    protected function makeTextProvider(string $name, ?string $model = null): TextProvider
    {
        $config = config("ai.providers.{$name}");

        if (! $config) {
            throw new InvalidArgumentException("مزود النص [{$name}] غير معرّف في config/ai.php");
        }

        if ($model) {
            $config['model'] = $model;
        }

        return match ($config['driver']) {
            'anthropic' => new AnthropicTextProvider($config),
            'openai' => new OpenAiTextProvider($config),
            'gemini' => new GeminiTextProvider($config),
            'fake' => new FakeTextProvider,
            default => throw new InvalidArgumentException("محرك نص غير مدعوم: {$config['driver']}"),
        };
    }

    protected function makeImageProvider(string $name): ImageProvider
    {
        $config = config("ai.providers.{$name}");

        if (! $config) {
            throw new InvalidArgumentException("مزود الصور [{$name}] غير معرّف في config/ai.php");
        }

        return match ($config['driver']) {
            'openai' => new OpenAiImageProvider($config),
            'gemini' => new GeminiImageProvider($config),
            'fake' => new FakeImageProvider,
            default => throw new InvalidArgumentException("محرك صور غير مدعوم: {$config['driver']}"),
        };
    }
}
