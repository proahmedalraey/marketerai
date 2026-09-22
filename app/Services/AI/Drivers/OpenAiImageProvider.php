<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\DTO\GeneratedImage;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\ImageResponse;
use App\Services\AI\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class OpenAiImageProvider implements ImageProvider
{
    public function __construct(protected array $config) {}

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->config['image_model'] ?? 'gpt-image-1';
    }

    public function generate(ImageRequest $request): ImageResponse
    {
        $startedAt = microtime(true);
        $dimensions = $request->dimensions();

        $response = $request->referenceImage
            ? $this->edit($request, $dimensions)
            : $this->create($request, $dimensions);

        if ($response->failed()) {
            throw ProviderException::fromStatus($this->name(), $response->status(), $response->body(), $response->header('Retry-After'));
        }

        $images = collect($response->json('data', []))
            ->map(function (array $item) use ($dimensions) {
                $binary = isset($item['b64_json'])
                    ? base64_decode($item['b64_json'])
                    : (isset($item['url']) ? Http::timeout(60)->get($item['url'])->body() : null);

                if ($binary === null || $binary === false) {
                    return null;
                }

                return new GeneratedImage(
                    contents: $binary,
                    mime: 'image/png',
                    width: $dimensions['width'],
                    height: $dimensions['height'],
                    meta: ['revised_prompt' => $item['revised_prompt'] ?? null],
                );
            })
            ->filter()
            ->values()
            ->all();

        if ($images === []) {
            throw new ProviderException('لم يعد المزود أي صورة صالحة.', $this->name(), null, true);
        }

        return new ImageResponse(
            images: $images,
            provider: $this->name(),
            model: $this->model(),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    protected function client(): PendingRequest
    {
        return Http::withToken($this->config['api_key'])->timeout($this->config['timeout'] ?? 180);
    }

    protected function create(ImageRequest $request, array $dimensions)
    {
        return $this->client()->post(rtrim($this->config['base_url'], '/').'/images/generations', [
            'model' => $this->model(),
            'prompt' => $request->prompt,
            'n' => $request->count,
            'size' => $this->apiSize($dimensions),
            'quality' => str_contains($request->quality, 'high') ? 'high' : 'medium',
        ]);
    }

    /**
     * تحرير صورة مرجعية للمنتج: يحافظ على شكل المنتج الحقيقي بدل تخيّله.
     */
    protected function edit(ImageRequest $request, array $dimensions)
    {
        $contents = str_starts_with($request->referenceImage, 'http')
            ? Http::timeout(60)->get($request->referenceImage)->body()
            : file_get_contents($request->referenceImage);

        return $this->client()
            ->attach('image[]', $contents, 'reference.png')
            ->post(rtrim($this->config['base_url'], '/').'/images/edits', [
                'model' => $this->model(),
                'prompt' => $request->prompt,
                'n' => $request->count,
                'size' => $this->apiSize($dimensions),
            ]);
    }

    protected function apiSize(array $dimensions): string
    {
        $ratio = $dimensions['width'] / max($dimensions['height'], 1);

        return match (true) {
            $ratio > 1.15 => '1536x1024',
            $ratio < 0.87 => '1024x1536',
            default => '1024x1024',
        };
    }
}
