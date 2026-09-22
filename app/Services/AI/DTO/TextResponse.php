<?php

namespace App\Services\AI\DTO;

class TextResponse
{
    public function __construct(
        public string $raw,
        public ?array $data,
        public string $provider,
        public string $model,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public int $latencyMs = 0,
        public float $costUsd = 0.0,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
