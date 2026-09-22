<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\ImageResponse;

interface ImageProvider
{
    public function generate(ImageRequest $request): ImageResponse;

    public function name(): string;

    public function model(): string;
}
