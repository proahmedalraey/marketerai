<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\DTO\GeneratedImage;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\ImageResponse;
use App\Services\AI\ProviderException;
use Illuminate\Support\Facades\Http;

/**
 * توليد الصور بنماذج Gemini الأصلية (Nano Banana وما بعده).
 *
 * كل طلب يعيد صورة واحدة، فالعدد يُطلب بعدة استدعاءات.
 * الصورة المرجعية للمنتج تُرسل مع النص في نفس الرسالة،
 * فيحافظ النموذج على شكل المنتج الحقيقي بدل تخيّله.
 */
class GeminiImageProvider implements ImageProvider
{
    public function __construct(protected array $config) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->config['image_model'] ?? 'gemini-2.5-flash-image';
    }

    public function generate(ImageRequest $request): ImageResponse
    {
        $startedAt = microtime(true);
        $dimensions = $request->dimensions();
        $reference = $request->referenceImage ? $this->referencePart($request->referenceImage) : null;

        $images = [];

        for ($i = 0; $i < max(1, $request->count); $i++) {
            $images[] = $this->generateOne($request, $reference, $dimensions);
        }

        return new ImageResponse(
            images: $images,
            provider: $this->name(),
            model: $this->model(),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    protected function generateOne(ImageRequest $request, ?array $reference, array $dimensions): GeneratedImage
    {
        $parts = [['text' => $request->prompt]];

        if ($reference) {
            $parts[] = $reference;
        }

        $imageConfig = ['aspectRatio' => $request->aspectRatio];

        // 1K هو الافتراضي؛ نطلب 2K صراحةً فقط، و2.5 لا يقبل الحقل أصلاً
        if (str_contains($request->quality, '2k') && ! str_contains($this->model(), '2.5')) {
            $imageConfig['imageSize'] = '2K';
        }

        $response = Http::withHeaders(['x-goog-api-key' => $this->config['api_key']])
            ->timeout($this->config['timeout'] ?? 180)
            ->post(rtrim($this->config['base_url'], '/')."/models/{$this->model()}:generateContent", [
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => [
                    'responseModalities' => ['IMAGE'],
                    'imageConfig' => $imageConfig,
                ],
            ]);

        if ($response->failed()) {
            throw ProviderException::fromStatus($this->name(), $response->status(), $response->body(), $response->header('Retry-After'));
        }

        if ($blocked = $response->json('promptFeedback.blockReason')) {
            throw new ProviderException("رفض Gemini الطلب ({$blocked}).", $this->name(), 200);
        }

        $image = collect($response->json('candidates.0.content.parts', []))
            ->first(fn ($part) => isset($part['inlineData']['data']));

        if (! $image) {
            $reason = $response->json('candidates.0.finishReason', 'بلا سبب');

            // STOP بلا صورة يحدث أحياناً ويمر بإعادة المحاولة؛ رفض المحتوى لا يمر
            throw new ProviderException(
                "لم يعد Gemini أي صورة ({$reason}).",
                $this->name(),
                200,
                retryable: $reason === 'STOP',
            );
        }

        return new GeneratedImage(
            contents: base64_decode($image['inlineData']['data']),
            mime: $image['inlineData']['mimeType'] ?? 'image/png',
            width: $dimensions['width'],
            height: $dimensions['height'],
        );
    }

    protected function referencePart(string $path): array
    {
        $contents = str_starts_with($path, 'http')
            ? Http::timeout(60)->get($path)->body()
            : file_get_contents($path);

        $mime = @getimagesizefromstring($contents)['mime'] ?? 'image/png';

        return ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($contents)]];
    }
}
