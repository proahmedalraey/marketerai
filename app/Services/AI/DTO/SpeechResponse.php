<?php

namespace App\Services\AI\DTO;

class SpeechResponse
{
    public function __construct(
        /** ملف WAV كامل (بترويسته) */
        public string $audio,
        public float $durationSeconds,
        public string $provider,
        public string $model,
        public string $mime = 'audio/wav',
        public int $tokensIn = 0,
        public int $tokensOut = 0,
        public int $latencyMs = 0,
        public float $costUsd = 0.0,
    ) {}
}
