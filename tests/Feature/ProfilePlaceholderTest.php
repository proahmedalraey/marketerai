<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\GenerationJob;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\Settings\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProfileFixtures;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * «الذكاء لا يولّد الأوصاف، ومتى يولّدها غير مفهوم».
 *
 * السبب كان عامل طابور بدأ قبل ضبط النموذج فبقي على المزود الوهمي، فخرجت
 * الأوصاف «[نص تجريبي]» والصفحة تعرضها كأنها أوصاف. الآن: الصفحة تسمّيها
 * وتقول السبب، وإعادتها مجانية ولا تُبقي منها شيئاً، والتوليد من الإجابات خطوة واحدة.
 */
class ProfilePlaceholderTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'محمد', 'email' => 'placeholder@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'امدادات القهوة',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function answer(array $answers = [], array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->put('/brand/profile/answers', ProfileFixtures::answers($answers) + $extra);
    }

    protected function active(): BrandProfile
    {
        return BrandProfile::forBrand($this->brand)->active()->firstOrFail();
    }

    /** الموقع يرى نموذجاً حقيقياً — والطابور القديم هو من استخدم المزود الوهمي. */
    protected function siteSeesARealModel(): void
    {
        app()->instance(AiManager::class, new class(app(AiSettings::class)) extends AiManager
        {
            public function text(?string $provider = null): TextProvider
            {
                return new class implements TextProvider
                {
                    public function generate(TextRequest $request): TextResponse
                    {
                        throw new \RuntimeException('لا يُستدعى في هذا الاختبار');
                    }

                    public function name(): string
                    {
                        return 'gemini';
                    }

                    public function model(): string
                    {
                        return 'gemini-3.6-flash';
                    }
                };
            }
        });
    }

    // ================================================================

    public function test_text_from_the_fake_provider_is_called_out_as_placeholder(): void
    {
        $this->answer()->assertSessionHasNoErrors();

        $this->assertSame('fake/fake-text-1', $this->active()->quality['model']);
        $this->assertTrue($this->active()->isPlaceholder());

        $this->actingAs($this->user)->get('/brand/profile')
            ->assertSee('هذه ليست أوصاف مشروعك')
            ->assertSee('لا يوجد نموذج ذكاء اصطناعي مفعّل')
            ->assertSee('أعد التوليد الآن · مجاناً');
    }

    public function test_when_the_site_has_a_real_model_the_page_points_at_the_stale_worker(): void
    {
        $this->answer();
        $this->siteSeesARealModel();

        $this->actingAs($this->user)->get('/brand/profile')
            ->assertSee('عامل الطابور')
            ->assertSee('start.bat');
    }

    public function test_regenerating_placeholder_text_is_free_and_keeps_nothing_from_it(): void
    {
        $this->answer();
        $this->assertStringContainsString('[نص تجريبي', $this->active()->technical['sales_summary']);

        ScriptedAiManager::install()->replyWith(ProfileFixtures::modelOutput());
        $this->actingAs($this->user)->post('/brand/profile/regenerate')->assertSessionHasNoErrors();

        $profile = $this->active();
        $this->assertEquals(100, $this->brand->refresh()->credit_balance, 'النص التجريبي خلل عندنا لا خطأ التاجر');
        $this->assertFalse($profile->isPlaceholder());
        $this->assertSame(ProfileFixtures::modelOutput()['sales_summary'], $profile->technical['sales_summary'],
            'الإجابات لم تتغير، لكن نسخة تجريبية ليس فيها ما يُبقى');
        $this->assertSame([], $profile->quality['kept']);
    }

    public function test_answers_can_be_saved_and_regenerated_in_one_step(): void
    {
        $ai = ScriptedAiManager::install();
        $ai->replyWith(ProfileFixtures::modelOutput());
        $this->answer();

        // «حفظ فقط»: مجاني ولا يولّد
        $this->answer(['audience' => 'أصحاب المقاهي في جدة'], ['regenerate' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(1, GenerationJob::where('type', 'brand_profile')->count());
        $this->assertEquals(100, $this->brand->refresh()->credit_balance);

        // «حفظ وإعادة توليد»: خطوة واحدة
        $ai->replyWith(ProfileFixtures::modelOutput());
        $this->answer(['audience' => 'أصحاب المقاهي في الرياض'], ['regenerate' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(2, GenerationJob::where('type', 'brand_profile')->count());
        $this->assertEquals(98, $this->brand->refresh()->credit_balance);
        $this->assertSame('أصحاب المقاهي في الرياض', $this->active()->answers['audience']);
    }

    public function test_the_free_save_is_the_default_for_the_enter_key(): void
    {
        ScriptedAiManager::install()->replyWith(ProfileFixtures::modelOutput());
        $this->answer();

        $html = $this->actingAs($this->user)->get('/brand/profile')->getContent();

        // Enter في حقل يضغط أول زر إرسال في النموذج: يجب أن يكون المجاني
        $this->assertLessThan(
            strpos($html, 'name="regenerate" value="1"'),
            strpos($html, 'name="regenerate" value="0"'),
        );
        $this->assertStringContainsString('يكتب الذكاء الاصطناعي الأوصاف الثلاثة من «الأسئلة والإجابات»', $html);
    }
}
