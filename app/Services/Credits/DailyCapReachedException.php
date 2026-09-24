<?php

namespace App\Services\Credits;

use RuntimeException;

class DailyCapReachedException extends RuntimeException
{
    public function __construct(
        public readonly float $cap,
        public readonly float $usedToday
    ) {
        parent::__construct("بلغت السقف اليومي ({$cap} نقطة). يمكنك المتابعة غداً أو ترقية الباقة.");
    }
}
