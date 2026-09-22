<?php

namespace App\Services\Credits;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $required,
        public readonly int $available,
        string $message = ''
    ) {
        parent::__construct($message ?: "الرصيد غير كافٍ: العملية تحتاج {$required} نقطة والمتاح {$available}.");
    }
}
