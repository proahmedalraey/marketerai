<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\GenerationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\ProfileFixtures;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * مرور رئيس التحرير: يرفع الوصفين من «مقبول» إلى «لافت»،
 * ولا يُقبل منه ما يضيف حقيقة لم ترد في الإجابات.
 */
class ProfilePolishTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected ScriptedAiManager $ai;

    protected function setUp(): void
    {
        parent::setUp();

        config(['brand.profile_polish' => true]);

        $this->ai = ScriptedAiManager::install();

        $this->user = User::create(['name' => 'محمد', 'email' => 'polish@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'امدادات القهوة',
            'banned_words' => ['رخيص'],
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function generate(string|array|\Throwable ...$replies): void
    {
        $this->ai->replyWith(...$replies);

        $this->actingAs($this->user)
            ->put('/brand/profile/answers', ProfileFixtures::answers())
            ->assertSessionHasNoErrors();
    }

    protected function active(): BrandProfile
    {
        return BrandProfile::forBrand($this->brand)->active()->firstOrFail();
    }

    protected function polished(array $overrides = []): array
    {
        return [
            'critique' => "1. الافتتاح عام؛ سأبدأ بساعة الذروة.\n7. لا توقيع؛ سأختم بجملة تُحفظ.",
            'simple' => 'امدادات القهوة: مورد ركن القهوة في مقهاك ومطعمك. مكونات المشروبات، والقهوة بأنواعها، وأدوات الباريستا، '
                .'ونتولى صيانة المكائن. وكلاء لعدة علامات تجارية عالمية، بالجملة أو بالتجزئة، والتوصيل مجاني للأنشطة التجارية داخل المدينة.',
            'detailed' => 'في ساعة الذروة لا وقت للبحث عن مورد آخر. '.ProfileFixtures::detailed(),
            ...$overrides,
        ];
    }

    public function test_the_editor_rewrites_the_descriptions_and_leaves_the_technical_description(): void
    {
        $this->generate(ProfileFixtures::modelOutput(), $this->polished());

        $profile = $this->active();

        $this->assertSame($this->polished()['simple'], $profile->simple);
        $this->assertStringStartsWith('في ساعة الذروة', $profile->detailed);
        $this->assertTrue($profile->quality['polished']);
        $this->assertTrue($profile->quality['passes']);
        $this->assertSame(ProfileFixtures::modelOutput()['advantages_directives'], $profile->technical['advantages_directives']);

        // المحرر يرى المدخلات والموجز والمسودة، ويراجع بمعايير لا بذوقه
        $editor = $this->ai->requests[1];
        $this->assertStringContainsString('رئيس تحرير', $editor->system);
        $this->assertStringContainsString('الافتتاح', $editor->system);
        $this->assertStringContainsString(ProfileFixtures::simple(), $editor->prompt);
        $this->assertStringContainsString('الوعد: امدادات القهوة', $editor->prompt);
        $this->assertStringContainsString(ProfileFixtures::answers()['audience'], $editor->prompt);
        $this->assertSame(['critique', 'simple', 'detailed'], array_keys($editor->schema['properties']));
    }

    public function test_an_editor_version_that_invents_a_fact_is_discarded(): void
    {
        $this->generate(ProfileFixtures::modelOutput(), $this->polished([
            'detailed' => ProfileFixtures::detailed().' نخدمكم منذ 2016.',
        ]));

        $profile = $this->active();

        $this->assertSame(ProfileFixtures::simple(), $profile->simple, 'الصدق فوق البلاغة: بقيت المسودة');
        $this->assertFalse($profile->quality['polished']);
        $this->assertTrue($profile->quality['passes']);
    }

    public function test_a_failed_editor_call_keeps_the_draft_and_completes_the_job(): void
    {
        $this->generate(ProfileFixtures::modelOutput(), new RuntimeException('انتهت الحصة'));

        $this->assertSame(ProfileFixtures::simple(), $this->active()->simple);
        $this->assertFalse($this->active()->quality['polished']);
        $this->assertSame(JobStatus::Completed, GenerationJob::latest('id')->first()->status);
    }

    public function test_the_editor_uses_the_profile_model(): void
    {
        config(['ai.profile.model' => 'openai/gpt-4.1']);

        $this->generate(ProfileFixtures::modelOutput(), $this->polished());

        $this->assertSame(['provider' => null, 'model' => 'openai/gpt-4.1'], $this->ai->routes[1]);
    }

    public function test_the_editor_can_be_turned_off(): void
    {
        config(['brand.profile_polish' => false]);

        $this->generate(ProfileFixtures::modelOutput());

        $this->assertCount(1, $this->ai->requests);
        $this->assertFalse($this->active()->quality['polished']);
    }

    public function test_the_eval_report_shows_the_critique_and_the_draft_before_editing(): void
    {
        $path = storage_path('framework/testing/brand-eval-'.uniqid().'.html');
        $this->ai->replyWith(ProfileFixtures::modelOutput(), $this->polished());

        $this->artisan('brand:eval-profile', ['--sample' => ['coffee'], '--html' => $path])->assertSuccessful();

        $html = file_get_contents($path);
        unlink($path);

        $this->assertStringContainsString('ملاحظات رئيس التحرير', $html);
        $this->assertStringContainsString('الافتتاح عام؛ سأبدأ بساعة الذروة.', $html);
        $this->assertStringContainsString('المسودة قبل التحرير', $html);
        $this->assertStringContainsString(e(ProfileFixtures::simple()), $html);
    }
}
