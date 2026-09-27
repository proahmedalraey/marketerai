<?php

namespace App\Services\AI;

use App\Models\AiUsageLog;
use App\Models\GenerationJob;
use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\Contracts\SpeechProvider;
use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\Drivers\AnthropicTextProvider;
use App\Services\AI\Drivers\FakeImageProvider;
use App\Services\AI\Drivers\FakeSpeechProvider;
use App\Services\AI\Drivers\FakeTextProvider;
use App\Services\AI\Drivers\GeminiImageProvider;
use App\Services\AI\Drivers\GeminiSpeechProvider;
use App\Services\AI\Drivers\GeminiTextProvider;
use App\Services\AI\Drivers\OpenAiImageProvider;
use App\Services\AI\Drivers\OpenAiTextProvider;
use App\Services\AI\Drivers\OpenRouterImageProvider;
use App\Services\AI\Drivers\OpenRouterTextProvider;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\ImageResponse;
use App\Services\AI\DTO\SpeechRequest;
use App\Services\AI\DTO\SpeechResponse;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\Settings\AiSettings;
use App\Support\JobStage;
use Illuminate\Http\Client\ConnectionException;
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

    public function text(?string $provider = null): TextProvider
    {
        $this->syncSettings();

        $key = 'text.'.($provider ?? config('ai.text_provider'));

        return $this->resolved[$key] ??= $this->makeTextProvider($provider ?? config('ai.text_provider'));
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

    public function speech(?string $provider = null): SpeechProvider
    {
        $this->syncSettings();

        $key = 'speech.'.($provider ?? config('ai.speech_provider'));

        return $this->resolved[$key] ??= $this->makeSpeechProvider($provider ?? config('ai.speech_provider'));
    }

    /**
     * توليد نص مع إعادة محاولة للأخطاء المؤقتة وتسجيل الاستهلاك.
     */
    public function generateText(TextRequest $request, ?GenerationJob $job = null, ?string $provider = null): TextResponse
    {
        $driver = $this->text($provider);

        // نموذج خاص بهذا الطلب: نسخة من الدرايفر بالنموذج الآخر، والدرايفر المخزَّن لا يتغير
        if ($request->model && method_exists($driver, 'withModel')) {
            $driver = $driver->withModel($request->model);
        }

        $attempt = 1;

        // المرحلة تظهر للتاجر: «يكتب…» ثم «يفحص…» بدل «جارية» طوال المهمة
        JobStage::set($job, JobStage::forOperation($request->operation));

        $response = $this->withRetries(
            fn () => $driver->generate($request),
            $driver->name(),
            $driver->model(),
            $request->operation,
            $job,
            $attempt
        );

        JobStage::set($job, JobStage::after($request->operation));

        $this->logUsage($job, $driver->name(), $response->model, $request->operation, [
            'attempt' => $attempt,
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

        $attempt = 1;

        JobStage::set($job, JobStage::forOperation($request->operation));

        $response = $this->withRetries(
            fn () => $driver->generate($request),
            $driver->name(),
            $driver->model(),
            $request->operation,
            $job,
            $attempt
        );

        $this->logUsage($job, $driver->name(), $response->model, $request->operation, [
            'attempt' => $attempt,
            'images' => $response->count(),
            'latency_ms' => $response->latencyMs,
            'cost_usd' => $response->costUsd,
        ]);

        return $response;
    }

    /**
     * نطق نص (التعليق الصوتي). المدة الفعلية تُسجَّل بدل عدد الصور، وهي أساس التسوية.
     */
    public function generateSpeech(SpeechRequest $request, ?GenerationJob $job = null, ?string $provider = null): SpeechResponse
    {
        $driver = $this->speech($provider);

        if ($request->model && method_exists($driver, 'withModel')) {
            $driver = $driver->withModel($request->model);
        }

        $attempt = 1;

        JobStage::set($job, JobStage::forOperation($request->operation));

        $response = $this->withRetries(
            fn () => $driver->synthesize($request),
            $driver->name(),
            $driver->model(),
            $request->operation,
            $job,
            $attempt
        );

        JobStage::set($job, JobStage::after($request->operation));

        $this->logUsage($job, $driver->name(), $response->model, $request->operation, [
            'attempt' => $attempt,
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
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

    /**
     * @param  int  $attemptUsed  يُملأ برقم المحاولة التي نجحت
     */
    protected function withRetries(callable $callback, string $provider, string $model, string $operation, ?GenerationJob $job, int &$attemptUsed = 1): mixed
    {
        $attempts = (int) config('ai.retry.times', 2) + 1;
        $sleep = (int) config('ai.retry.sleep_ms', 1500);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $startedAt = microtime(true);

            try {
                try {
                    $result = $callback();
                } catch (ConnectionException $e) {
                    // انقطاع قبل الوصول للمزود (DNS/رفض اتصال) يمر بمنطق الإعادة نفسه؛ المهلة تبقى كما هي
                    throw ProviderException::isUnreachable($e) ? ProviderException::fromConnection($provider, $e) : $e;
                }

                $attemptUsed = $attempt;

                return $result;
            } catch (ProviderException $e) {
                $isLast = $attempt === $attempts;
                $willRetry = ! $isLast && $e->retryable;

                Log::warning('فشل استدعاء مزود ذكاء اصطناعي', [
                    'provider' => $provider,
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'retryable' => $e->retryable,
                    'quota_exhausted' => $e->quotaExhausted,
                    'message' => $e->getMessage(),
                ]);

                // ما يطلبه المزود صراحة يعلو على تقديرنا: المحاولة قبله مرفوضة سلفاً
                $waitMs = $willRetry ? max($sleep * $attempt, (int) ($e->retryAfterSeconds ?? 0) * 1000) : 0;

                $this->logFailure($job, $provider, $model, $operation, $attempt, $startedAt, $e->statusCode, $e->getMessage(), $waitMs);

                if (! $willRetry) {
                    throw $e;
                }

                usleep($waitMs * 1000);
            } catch (\Throwable $e) {
                // مهلة أو انقطاع اتصال أو خطأ غير متوقع: لا يُعاد، لكن وقته حقيقي ويجب أن يظهر
                $this->logFailure($job, $provider, $model, $operation, $attempt, $startedAt, null, $e->getMessage(), 0);

                throw $e;
            }
        }

        throw new ProviderException('تعذر إكمال الطلب بعد كل المحاولات.', $provider);
    }

    /**
     * محاولة فاشلة: تُسجَّل بزمنها الفعلي وما انتظرناه بعدها.
     * هذا ما كان مخفياً: زمن مهمة أطول من مجموع طلباتها الناجحة.
     */
    protected function logFailure(?GenerationJob $job, string $provider, string $model, string $operation, int $attempt, float $startedAt, ?int $status, string $message, int $waitedMs): void
    {
        $this->logUsage($job, $provider, $model, $operation, [
            'succeeded' => false,
            'attempt' => $attempt,
            'status_code' => $status,
            'waited_ms' => $waitedMs,
            'error' => mb_substr($message, 0, 255),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
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

    protected function makeTextProvider(string $name): TextProvider
    {
        $config = config("ai.providers.{$name}");

        if (! $config) {
            throw new InvalidArgumentException("مزود النص [{$name}] غير معرّف في config/ai.php");
        }

        return match ($config['driver']) {
            'anthropic' => new AnthropicTextProvider($config),
            'openai' => new OpenAiTextProvider($config),
            'gemini' => new GeminiTextProvider($config),
            'openrouter' => new OpenRouterTextProvider($config),
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
            'openrouter' => new OpenRouterImageProvider($config),
            'fake' => new FakeImageProvider,
            default => throw new InvalidArgumentException("محرك صور غير مدعوم: {$config['driver']}"),
        };
    }

    protected function makeSpeechProvider(string $name): SpeechProvider
    {
        $config = config("ai.providers.{$name}");

        if (! $config) {
            throw new InvalidArgumentException("مزود الصوت [{$name}] غير معرّف في config/ai.php");
        }

        return match ($config['driver']) {
            'gemini' => new GeminiSpeechProvider($config),
            'fake' => new FakeSpeechProvider,
            default => throw new InvalidArgumentException("محرك صوت غير مدعوم: {$config['driver']}"),
        };
    }
}
