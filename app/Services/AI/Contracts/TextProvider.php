<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;

interface TextProvider
{
    public function generate(TextRequest $request): TextResponse;

    public function name(): string;

    public function model(): string;
}
