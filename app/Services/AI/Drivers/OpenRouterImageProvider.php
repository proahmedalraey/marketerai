<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\DTO\GeneratedImage;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\ImageResponse;
use App\Services\AI\ModelCatalog;
use App\Services\AI\ProviderException;
use App\Services\Media\StudioModels;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * صور عبر OpenRouter (/images): نموذج واحد من عشرات نماذج الصور بمفتاح واحد.
 *
 * كل نموذج يقبل خيارات مختلفة (الجودة، الدقة، عدد الصور المرجعية)، وإرسال خيار
 * لا يعرفه قد يُسقط الطلب. لذلك نقرأ قدرات النموذج من OpenRouter (مخزَّنة ساعة)
 * ونرسل ما يدعمه فقط. كل طلب صورة واحدة: بعض النماذج لا تقبل أكثر من واحدة.
 */
class OpenRouterImageProvider implements ImageProvider
{
    public function __construct(protected array $config) {}

    public function name(): string
    {
        return 'openrouter';
    }

    public function model(): string
    {
        return $this->config['image_model'] ?? 'google/gemini-3.1-flash-image';
    }

    public function generate(ImageRequest $request): ImageResponse
    {
        $startedAt = microtime(true);
        $dimensions = $request->dimensions();
        // نموذج الطلب (اختيار الاستوديو) يعلو النموذج الافتراضي من الإعدادات
        $model = $request->model ?: $this->model();
        $params = app(ModelCatalog::class)->imageParameters($model);

        $reference = $request->referenceImage ? $this->referencePart($request->referenceImage, $params, $model) : null;

        $body = $this->requestBody($request, $model, $params, $reference);
        $count = max(1, $request->count);

        [$images, $cost] = $count === 1
            ? $this->generateOne($body, $dimensions)
            : $this->generateMany($body, $count, $dimensions);

        return new ImageResponse(
            images: $images,
            provider: $this->name(),
            model: $model,
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            costUsd: $cost,
        );
    }

    /** جسم طلب صورة واحدة: بعض النماذج لا تقبل n>1، فالعدد يُنفَّذ بطلبات منفصلة. */
    protected function requestBody(ImageRequest $request, string $model, ?array $params, ?array $reference): array
    {
        $body = [
            'model' => $model,
            'prompt' => $request->prompt,
            'n' => 1,
            'output_format' => 'png',
        ];

        // نسبة غير مدعومة تُرسَل أقرب نسبة مدعومة؛ ImageGenerationService يقصّ الناتج للنسبة المطلوبة
        if ($ratio = $this->nearestRatio($params, $request->aspectRatio)) {
            $body['aspect_ratio'] = $ratio;
        }

        [$wantedResolution, $wantedQuality] = $this->parseQuality($request->quality);

        if ($resolution = $this->choose($params, 'resolution', $wantedResolution)) {
            $body['resolution'] = $resolution;
        }

        if ($quality = $this->choose($params, 'quality', $wantedQuality)) {
            $body['quality'] = $quality;
        }

        if ($request->background && ($background = $this->choose($params, 'background', $request->background))) {
            $body['background'] = $background;
        }

        // نماذج لا تقبل output_format (مثل بعض نماذج الفيكتور) لا يجب أن تُسقط الطلب
        if ($params !== null && ! isset($params['output_format'])) {
            unset($body['output_format']);
        }

        if ($reference) {
            $body['input_references'] = [$reference];
        }

        return $body;
    }

    protected function configure(PendingRequest $http): PendingRequest
    {
        return $http
            ->withToken($this->config['api_key'])
            ->withHeaders(OpenRouterTextProvider::identityHeaders())
            ->timeout($this->config['timeout'] ?? 180);
    }

    protected function endpoint(): string
    {
        return rtrim($this->config['base_url'], '/').'/images';
    }

    /** @return array{0: list<GeneratedImage>, 1: float} */
    protected function generateOne(array $body, array $dimensions): array
    {
        [$image, $cost] = $this->decode($this->configure(Http::withOptions([]))->post($this->endpoint(), $body), $dimensions);

        return [[$image], $cost];
    }

    /**
     * طلبات متوازية: زمن الأربع صور ≈ زمن صورة (كان تسلسلياً: Grok 2.0 بأربع صور ≈ 4 دقائق).
     * فشل بعضها لا يُسقط ما نجح: صور دُفع ثمنها عند المزود لا تُرمى، وتُرجع الخدمة نقاط الناقص.
     * إن فشلت كلها يُرمى أول خطأ ليعمل منطق إعادة المحاولة المعتاد.
     *
     * @return array{0: list<GeneratedImage>, 1: float}
     */
    protected function generateMany(array $body, int $count, array $dimensions): array
    {
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (int $i) => $this->configure($pool->as("image{$i}"))->post($this->endpoint(), $body),
            range(1, $count),
        ));

        $images = [];
        $cost = 0.0;
        $failure = null;

        foreach ($responses as $response) {
            try {
                if ($response instanceof Throwable) {
                    throw $response;
                }

                [$image, $callCost] = $this->decode($response, $dimensions);
                $images[] = $image;
                $cost += $callCost;
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($images === []) {
            throw $failure;
        }

        return [$images, $cost];
    }

    /** @return array{0: GeneratedImage, 1: float} */
    protected function decode(Response $response, array $dimensions): array
    {
        if ($response->failed()) {
            throw ProviderException::fromStatus($this->name(), $response->status(), $response->body(), $response->header('Retry-After'));
        }

        if ($error = $response->json('error')) {
            $code = (int) data_get($error, 'code', 502);

            throw ProviderException::fromStatus(
                $this->name(),
                $code >= 400 && $code < 600 ? $code : 502,
                json_encode(['error' => $error], JSON_UNESCAPED_UNICODE),
            );
        }

        $item = collect($response->json('data', []))
            ->first(fn ($i) => ! empty($i['b64_json']) || ! empty($i['url']));

        $binary = match (true) {
            $item === null => null,
            ! empty($item['b64_json']) => base64_decode($item['b64_json'], true),
            default => Http::timeout(60)->get($item['url'])->body(),
        };

        if (! $binary) {
            throw new ProviderException('لم يعد OpenRouter أي صورة صالحة.', $this->name(), 200, retryable: true);
        }

        return [
            new GeneratedImage(
                contents: $binary,
                mime: $item['media_type'] ?? 'image/png',
                width: $dimensions['width'],
                height: $dimensions['height'],
            ),
            (float) $response->json('usage.cost', 0),
        ];
    }

    /**
     * مفتاح الجودة → [دقة، جودة] كما يفهمها OpenRouter.
     * المصفوفة الجديدة "{1k|2k|4k}_{low|medium|high|very_high|max}" وما قبلها
     * ("standard_1k"، "high_2k") — كان الفحص القديم يرسل 4K كـ1K و"قصوى" كمتوسطة.
     *
     * @return array{0: string, 1: string}
     */
    protected function parseQuality(string $key): array
    {
        if (preg_match('/^(1k|2k|4k)_(\w+)$/', $key, $m)) {
            return [strtoupper($m[1]), StudioModels::QUALITY_MAP[$m[2]] ?? 'medium'];
        }

        return [
            str_contains($key, '2k') ? '2K' : '1K',
            str_contains($key, 'high') ? 'high' : 'medium',
        ];
    }

    /** أقرب نسبة يقبلها النموذج للمطلوبة (بالمسافة اللوغاريتمية). قدرات مجهولة = المطلوبة كما هي. */
    protected function nearestRatio(?array $params, string $wanted): ?string
    {
        if ($params === null) {
            return $wanted;
        }

        $values = collect($params['aspect_ratio']['values'] ?? [])->reject(fn ($v) => $v === 'auto')->values();

        if ($values->isEmpty()) {
            return null;
        }

        if ($values->contains($wanted)) {
            return $wanted;
        }

        $number = function (string $ratio): float {
            [$w, $h] = array_map('floatval', explode(':', $ratio) + [1 => 1]);

            return $h > 0 ? $w / $h : 1.0;
        };

        $target = log($number($wanted) ?: 1.0);

        return $values->sortBy(fn ($v) => abs(log($number($v) ?: 1.0) - $target))->first();
    }

    /**
     * القيمة إن كان النموذج يقبلها. قدرات النموذج مجهولة (تعذر جلبها) = لا نرسل شيئاً زائداً
     * سوى نسبة الأبعاد، فهي مدعومة عند كل النماذج التي فحصناها.
     */
    protected function choose(?array $params, string $name, string $wanted): ?string
    {
        if ($params === null) {
            return $name === 'aspect_ratio' ? $wanted : null;
        }

        $spec = $params[$name] ?? null;

        if (! $spec) {
            return null;
        }

        return in_array($wanted, $spec['values'] ?? [], true) ? $wanted : null;
    }

    /**
     * صورة المنتج المرجعية: إن لم يقبلها النموذج نفشل بوضوح بدل توليد صورة
     * لمنتج متخيَّل لا يشبه منتج التاجر.
     */
    protected function referencePart(string $path, ?array $params, string $model): array
    {
        if ($params !== null && (int) data_get($params, 'input_references.max', 0) < 1) {
            throw new ProviderException(
                "النموذج {$model} لا يقبل صورة مرجعية للمنتج.",
                $this->name(),
                400,
                display: 'نموذج الصور المختار لا يدعم صورة المنتج المرجعية. اختر نموذج صور آخر من الإعدادات. أُرجعت نقاطك.',
            );
        }

        $unusable = fn (?int $status = null) => new ProviderException(
            'تعذّر تنزيل الصورة المرجعية'.($status ? " (HTTP {$status})" : '').": {$path}",
            $this->name(),
            $status,
            display: 'تعذّر تنزيل صورة المنتج المرجعية من متجرك. جرّب منتجاً آخر أو ارفع الصورة من جهازك. أُرجعت نقاطك.',
        );

        if (str_starts_with($path, 'http')) {
            $download = Http::timeout(60)->get($path);

            // صفحة خطأ (403/404) كانت تُرسَل للنموذج كأنها صورة فيرفضها بخطأ غامض
            if ($download->failed()) {
                throw $unusable($download->status());
            }

            $contents = $download->body();
        } else {
            $contents = (string) @file_get_contents($path);
        }

        $mime = @getimagesizefromstring($contents)['mime'] ?? null;

        if (! $mime) {
            throw $unusable();
        }

        return [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($contents)],
        ];
    }
}
