<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\ImageProvider;
use App\Services\AI\DTO\GeneratedImage;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\ImageResponse;

/**
 * يولّد صوراً بديلة محلياً عبر GD، فتعمل كل مسارات الاستوديو
 * (المعرض، الحفظ، التحميل، الكاروسيل) دون أي تكلفة توليد.
 */
class FakeImageProvider implements ImageProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-image-1';
    }

    public function generate(ImageRequest $request): ImageResponse
    {
        $dimensions = $request->dimensions();
        $images = [];

        for ($i = 0; $i < $request->count; $i++) {
            $images[] = new GeneratedImage(
                contents: $this->placeholder($dimensions['width'], $dimensions['height'], $i),
                mime: 'image/png',
                width: $dimensions['width'],
                height: $dimensions['height'],
                seed: 'fake-'.($request->seed ?? bin2hex(random_bytes(4))),
                meta: ['placeholder' => true, 'prompt' => $request->prompt],
            );
        }

        return new ImageResponse(
            images: $images,
            provider: $this->name(),
            model: $this->model(),
            latencyMs: 500,
        );
    }

    protected function placeholder(int $width, int $height, int $index): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            // إضافة GD غير مثبتة: نعيد صورة PNG صغيرة صالحة
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        }

        $image = imagecreatetruecolor($width, $height);
        $palette = [[249, 115, 22], [234, 88, 12], [194, 65, 12], [154, 52, 18]];
        $rgb = $palette[$index % count($palette)];

        $background = imagecolorallocate($image, ...$rgb);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        $white = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 24, 24, 'PLACEHOLDER '.($index + 1), $white);
        imagestring($image, 3, 24, 52, $width.'x'.$height, $white);

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
}
