<?php

namespace App\Services\AI\DTO;

class ImageResponse
{
    /** @param array<int, GeneratedImage> $images */
    public function __construct(
        public array $images,
        public string $provider,
        public string $model,
        public int $latencyMs = 0,
        public float $costUsd = 0.0,
    ) {}

    public function count(): int
    {
        return count($this->images);
    }
}
