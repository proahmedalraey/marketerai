<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\Support\ProfileFixtures;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * طرفية ويندوز تعرض العربية معكوسة الحروف، فالتقييم يكتب تقريراً يُقرأ في المتصفح.
 */
class EvalProfileReportTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/brand-eval-'.uniqid().'.html');
    }

    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    public function test_every_run_writes_a_right_to_left_report(): void
    {
        ScriptedAiManager::install()->replyWith(ProfileFixtures::modelOutput());

        $this->artisan('brand:eval-profile', ['--sample' => ['coffee'], '--html' => $this->path])
            ->expectsOutputToContain($this->path)
            ->assertSuccessful();

        $html = File::get($this->path);

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('charset="utf-8"', $html);

        // النصوص كاملة كما كتبها النموذج، والمدخلات بجانبها للمقارنة
        $this->assertStringContainsString(e(ProfileFixtures::simple()), $html);
        $this->assertStringContainsString('الموجز الاستراتيجي', $html);
        $this->assertStringContainsString('الوعد: امدادات القهوة', $html);
        $this->assertStringContainsString(e(ProfileFixtures::answers()['audience']), $html);
        $this->assertStringContainsString('لا مشاكل.', $html);
    }

    public function test_a_failed_call_is_reported_not_fatal(): void
    {
        ScriptedAiManager::install()->replyWith(new \RuntimeException('انتهت الحصة'));

        $this->artisan('brand:eval-profile', ['--sample' => ['vague'], '--html' => $this->path])
            ->assertFailed();

        $this->assertStringContainsString('انتهت الحصة', File::get($this->path));
    }
}
