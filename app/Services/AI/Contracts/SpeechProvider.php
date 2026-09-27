<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTO\SpeechRequest;
use App\Services\AI\DTO\SpeechResponse;

interface SpeechProvider
{
    public function synthesize(SpeechRequest $request): SpeechResponse;

    public function name(): string;

    public function model(): string;
}
