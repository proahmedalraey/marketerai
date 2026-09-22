<?php

namespace App\Services\AI\DTO;

class TextRequest
{
    public function __construct(
        public string $system,
        public string $prompt,
        /** مخطط JSON متوقع للمخرج، أو null لنص حر */
        public ?array $schema = null,
        public float $temperature = 0.8,
        public int $maxTokens = 2048,
        public string $operation = 'content.post',
    ) {}
}
