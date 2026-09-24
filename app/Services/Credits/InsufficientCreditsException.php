<?php

namespace App\Services\Credits;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly float $required,
        public readonly float $available,
        string $message = ''
    ) {
        parent::__construct($message ?: "الرصيد غير كافٍ: العملية تحتاج {$required} نقطة والمتاح {$available}.");
    }
}
