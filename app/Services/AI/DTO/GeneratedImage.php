<?php

namespace App\Services\AI\DTO;

class GeneratedImage
{
    public function __construct(
        /** محتوى الصورة الثنائي */
        public string $contents,
        public string $mime = 'image/png',
        public ?int $width = null,
        public ?int $height = null,
        public ?string $seed = null,
        public array $meta = [],
    ) {}
}
