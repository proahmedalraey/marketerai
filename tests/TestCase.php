<?php

namespace Tests;

use App\Support\CurrentBrand;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CurrentBrand ثابت يعيش بين الاختبارات: براند اختبار سابق (بمعرّف من قاعدة
        // بيانات مُعادة) كان يمرّر اختبارات العزل صدفةً أو يُسقط غيرها.
        CurrentBrand::clear();
    }
}
