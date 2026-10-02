<?php

namespace Tests\Feature;

use App\Services\Brand\Quality\ProfileExpectations;
use Illuminate\Support\Facades\File;
use Tests\Support\ProfileFixtures;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * مجموعة تقييم الهوية: حالات من ملف بتوقعات مكتوبة قبل التشغيل، وتكرار، ومحكّم يقتبس.
 */
class EvalProfileDatasetTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/brand-eval-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    protected function dataset(array $expect): string
    {
        $path = "{$this->dir}/cases.json";

        File::put($path, json_encode(['cases' => [[
            'key' => 'supplies',
            'about' => 'مستلزمات كافيهات — جملة وتجزئة',
            'meta' => ['model' => 'both'],
            'brand' => ['business_type' => 'good', 'dialect' => 'saudi'],
            'answers' => ProfileFixtures::answers(),
            'expect' => $expect,
        ]]], JSON_UNESCAPED_UNICODE));

        return $path;
    }

    protected function judgeReply(array $defects = []): array
    {
        return [
            'scores' => [
                'faithfulness' => 4, 'constraints' => 5, 'classification' => 5, 'coverage' => 4,
                'consistency' => 5, 'language' => 4, 'specificity' => 3, 'audience_fit' => 5,
            ],
            'classification' => 'both',
            'defects' => $defects,
            'summary' => 'ملف دقيق، والافتتاح عام.',
        ];
    }

    public function test_dataset_cases_run_the_production_path_and_export_everything_for_analysis(): void
    {
        // رئيس التحرير معطل في الاختبارات (phpunit.xml): المسودة ثم المحكّم
        $ai = ScriptedAiManager::install()->replyWith(
            ProfileFixtures::modelOutput(),
            $this->judgeReply([
                ['field' => 'detailed', 'category' => 'generic', 'severity' => 'medium', 'quote' => 'يحتاج مستلزمات كثيرة جاهزة قبل أول طلب', 'why' => 'افتتاح عام', 'fix' => 'مشهد محدد'],
                ['field' => 'simple', 'category' => 'fabrication', 'severity' => 'high', 'quote' => 'شحن مجاني لكل دول العالم', 'why' => 'غير موجود', 'fix' => '—'],
            ]),
        );

        $this->artisan('brand:eval-profile', [
            '--dataset' => $this->dataset([
                'classification' => 'both',
                'mentions' => [['any' => ['الجملة', 'جملة'], 'in' => 'descriptions', 'label' => 'البيع بالجملة']],
            ]),
            '--judge' => true,
            '--json' => "{$this->dir}/results.json",
            '--html' => "{$this->dir}/report.html",
        ])->assertSuccessful();

        // المحكّم طلب منفصل بنموذجه المضبوط، بعد مسار الإنتاج
        $this->assertCount(2, $ai->requests);
        $this->assertSame('eval.judge', $ai->requests[1]->operation);
        $this->assertSame(config('ai.eval_judge.model'), $ai->routes[1]['model']);

        $json = json_decode(File::get("{$this->dir}/results.json"), true);
        $run = $json['results'][0];

        $this->assertSame('supplies', $run['case']);
        $this->assertSame(1, $run['attempts']);
        $this->assertSame([], $run['expectations']);
        $this->assertTrue($run['passes']);
        $this->assertSame(4.38, $run['judge']['overall']);

        // اقتباس موجود في المخرج موثّق، واقتباس لا أصل له غير موثّق
        $this->assertTrue($run['judge']['defects'][0]['verified']);
        $this->assertFalse($run['judge']['defects'][1]['verified']);
    }

    public function test_an_expectation_broken_by_the_output_fails_the_case_without_a_judge(): void
    {
        ScriptedAiManager::install()->replyWith(ProfileFixtures::modelOutput());

        $this->artisan('brand:eval-profile', [
            '--dataset' => $this->dataset([
                'absent' => [['any' => ['التوصيل مجاني'], 'in' => 'descriptions', 'label' => 'ممنوع في هذه الحالة']],
                'mentions' => [['any' => ['الرياض'], 'in' => 'descriptions', 'label' => 'المدينة']],
            ]),
            '--json' => "{$this->dir}/results.json",
            '--html' => "{$this->dir}/report.html",
        ])->assertFailed();

        $run = json_decode(File::get("{$this->dir}/results.json"), true)['results'][0];

        $this->assertFalse($run['passes']);
        $this->assertNull($run['judge']);
        $this->assertEqualsCanonicalizing(['unexpected_mention', 'expected_missing'], array_column($run['expectations'], 'code'));

        // التوقعات تظهر في التقرير مع مشاكل الفحص العام
        $this->assertStringContainsString('ممنوع في هذه الحالة', File::get("{$this->dir}/report.html"));
    }

    public function test_repeat_runs_each_case_several_times_under_numbered_keys(): void
    {
        ScriptedAiManager::install()->replyWith(ProfileFixtures::modelOutput(), ProfileFixtures::modelOutput());

        $this->artisan('brand:eval-profile', [
            '--dataset' => $this->dataset([]),
            '--repeat' => 2,
            '--json' => "{$this->dir}/results.json",
            '--html' => "{$this->dir}/report.html",
        ])->assertSuccessful();

        $results = json_decode(File::get("{$this->dir}/results.json"), true)['results'];

        $this->assertSame(['supplies#1', 'supplies#2'], array_column($results, 'key'));
        $this->assertSame([1, 2], array_column($results, 'run'));
    }

    public function test_a_missing_or_malformed_dataset_is_rejected_before_any_model_call(): void
    {
        $ai = ScriptedAiManager::install();
        File::put("{$this->dir}/bad.json", '{"cases": [{"answers": {}}]}');

        $this->artisan('brand:eval-profile', ['--dataset' => "{$this->dir}/missing.json"])->assertExitCode(2);
        $this->artisan('brand:eval-profile', ['--dataset' => "{$this->dir}/bad.json"])->assertExitCode(2);

        $this->assertSame([], $ai->requests);
    }

    public function test_the_shipped_dataset_is_valid_and_covers_every_sales_model(): void
    {
        $cases = json_decode(File::get(base_path('tests/Evals/brand-profile.json')), true)['cases'];

        $this->assertGreaterThanOrEqual(30, count($cases));
        $this->assertCount(count($cases), array_unique(array_column($cases, 'key')));

        foreach ($cases as $case) {
            $this->assertNotEmpty($case['answers']['project_name'], $case['key']);
            $this->assertContains($case['answers']['type'], ['good', 'service'], $case['key']);

            foreach ([...($case['expect']['mentions'] ?? []), ...($case['expect']['absent'] ?? [])] as $rule) {
                $this->assertContains($rule['in'], ProfileExpectations::SCOPES, $case['key']);
                $this->assertNotEmpty($rule['any'], $case['key']);
            }
        }

        $this->assertEqualsCanonicalizing(
            ['retail', 'wholesale', 'both', 'service_b2c', 'service_b2b', 'service_both'],
            array_values(array_unique(array_column(array_column($cases, 'expect'), 'classification'))),
        );
    }
}
