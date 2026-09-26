<?php

namespace Tests\Feature;

use App\Enums\ProfileSource;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Models\User;
use App\Services\AI\Prompts\PromptBuilder;
use App\Services\Brand\BrandProfileGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProfileFixtures;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * المرحلة 3 من خطة التدقيق: استقرار الهوية.
 *
 * تدقيق المنافس: بنفس الإجابات حرفياً ولّد ثلاثة قيود ثم «لا توجد ملاحظات» (H2)،
 * وتغيير إجابة واحدة أعاد كتابة كل شيء (H3)، وتعليمة التاجر تُجوهلت بصمت (C7).
 */
class IdentityStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected ScriptedAiManager $ai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = ScriptedAiManager::install();
        config(['ai.proofread.enabled' => false]);

        $this->user = User::create(['name' => 'محمد', 'email' => 'stable@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'امدادات القهوة',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function answer(array $answers = [], ?array $reply = null): void
    {
        if ($reply !== null) {
            $this->ai->replyWith($reply);
        }

        $this->actingAs($this->user)
            ->put('/brand/profile/answers', ProfileFixtures::answers($answers))
            ->assertSessionHasNoErrors();
    }

    protected function regenerate(array $reply): void
    {
        $this->ai->replyWith($reply);
        $this->actingAs($this->user)->post('/brand/profile/regenerate')->assertSessionHasNoErrors();
    }

    protected function active(): BrandProfile
    {
        return BrandProfile::forBrand($this->brand)->active()->firstOrFail();
    }

    /** النسخة النشطة كأن برومبتاً أقدم كتبها قبل تتبّع مصدر كل حقل. */
    protected function makeActiveLegacy(array $quality = []): void
    {
        $profile = $this->active();
        $profile->update(['quality' => [
            ...collect($profile->quality)->except(['technical_versions', 'prompt_version'])->all(),
            ...$quality,
        ]]);
    }

    /** رد مختلف في كل حقل: إن بقي حقل كما كان، فالبقاء قرار الكود لا صدفة النموذج. */
    protected function differentReply(): array
    {
        return ProfileFixtures::modelOutput([
            'activity_type' => 'صياغة جديدة لنوع النشاط',
            'sales_summary' => 'صياغة جديدة لملخص المبيعات.',
            'advantages_directives' => 'صياغة جديدة لتوجيهات المزايا.',
            'important_notes' => ['ملاحظة جديدة من النموذج.'],
        ]);
    }

    // ================================================================
    //  H2 — القيود لا تختفي
    // ================================================================

    public function test_fixed_rules_are_there_even_when_the_model_writes_none(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput(['important_notes' => []]));

        $this->assertSame(BrandProfileGenerator::FIXED_NOTES, $this->active()->constraints());

        $system = PromptBuilder::make()->forBrand($this->brand->refresh())->system();
        foreach (BrandProfileGenerator::FIXED_NOTES as $note) {
            $this->assertStringContainsString($note, $system);
        }
    }

    public function test_regenerating_unchanged_answers_keeps_the_technical_description(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput());
        $before = $this->active()->technical;

        $this->regenerate($this->differentReply());

        $after = $this->active();
        foreach (['activity_type', 'sales_summary', 'advantages_directives', 'important_notes'] as $field) {
            $this->assertSame($before[$field], $after->technical[$field], "{$field} تغيّر بلا سبب");
        }

        $this->assertSame(['activity_type', 'sales_summary', 'advantages_directives', 'important_notes'], $after->quality['kept']);
        $this->assertSame(2, $after->version, 'الوصفان يُعاد توليدهما في نسخة جديدة');
    }

    public function test_a_profile_written_by_an_older_prompt_is_fully_rewritten(): void
    {
        // تحسين البرومبت لا يصل لمن لم تتغير إجاباته إن أُبقي وصفه التقني القديم
        $this->answer(reply: ProfileFixtures::modelOutput());
        $this->makeActiveLegacy(['prompt_version' => 1]);

        $this->regenerate($this->differentReply());

        $after = $this->active();
        $this->assertSame('صياغة جديدة لتوجيهات المزايا.', $after->technical['advantages_directives']);
        $this->assertSame('صياغة جديدة لنوع النشاط', $after->technical['activity_type']);
        $this->assertSame([], $after->quality['kept']);
        $this->assertSame(BrandProfileGenerator::PROMPT_VERSION, $after->quality['prompt_version']);
        $this->assertFalse($after->writtenByOlderPrompt());
    }

    public function test_versions_from_before_prompt_versioning_count_as_older(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput());
        $this->makeActiveLegacy();

        $this->assertTrue($this->active()->writtenByOlderPrompt());
    }

    public function test_text_kept_from_an_older_version_does_not_inherit_the_new_stamp(): void
    {
        // حالة حقيقية (v16): مختومة بالإصدار الحالي، لكنها أبقت الوصف التقني كله من نسخة أقدم
        $this->answer(reply: ProfileFixtures::modelOutput());
        $this->makeActiveLegacy([
            'prompt_version' => BrandProfileGenerator::PROMPT_VERSION,
            'kept' => ['activity_type', 'sales_summary', 'advantages_directives', 'important_notes'],
        ]);

        $this->assertTrue($this->active()->writtenByOlderPrompt());

        $this->regenerate($this->differentReply());

        $this->assertSame('صياغة جديدة لتوجيهات المزايا.', $this->active()->technical['advantages_directives']);
        $this->assertSame([], $this->active()->quality['kept']);
    }

    public function test_editing_a_description_does_not_protect_old_technical_text(): void
    {
        // تعديل الوصف المبسط يجعل النسخة «يدوية»، لكن وصفها التقني ما زال نص البرومبت القديم
        $this->answer(reply: ProfileFixtures::modelOutput());
        $this->makeActiveLegacy(['prompt_version' => 1]);

        $this->actingAs($this->user)->put('/brand/profile', ['field' => 'simple', 'simple' => 'وصف كتبه التاجر بنفسه لمتجره.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ProfileSource::ManualEdit, $this->active()->source);
        $this->assertTrue($this->active()->writtenByOlderPrompt());

        $this->regenerate($this->differentReply());

        $this->assertSame('صياغة جديدة لتوجيهات المزايا.', $this->active()->technical['advantages_directives']);
    }

    // ================================================================
    //  H3 — تغيير إجابة يعيد كتابة ما يعتمد عليها فقط
    // ================================================================

    public function test_changing_one_answer_rewrites_only_what_depends_on_it(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput());
        $before = $this->active()->technical;

        $this->answer(['advantages' => "وكلاء لعدة علامات تجارية عالمية\nصيانة مكائن خلال 48 ساعة"]);
        $this->regenerate($this->differentReply());

        $after = $this->active()->technical;

        $this->assertSame($before['activity_type'], $after['activity_type']);
        $this->assertSame($before['sales_summary'], $after['sales_summary']);
        $this->assertSame('صياغة جديدة لتوجيهات المزايا.', $after['advantages_directives']);
        $this->assertContains('ملاحظة جديدة من النموذج.', $after['important_notes']);
        $this->assertSame(['activity_type', 'sales_summary'], $this->active()->quality['kept']);
    }

    public function test_a_manual_edit_of_the_technical_description_survives_regeneration(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput());

        $this->actingAs($this->user)->put('/brand/profile', [
            'field' => 'technical',
            'technical' => [
                'activity_type' => 'توريد مستلزمات المقاهي',
                'sales_summary' => 'ملخص كتبه التاجر بنفسه.',
                'advantages_directives' => $this->active()->technical['advantages_directives'],
                'important_notes' => implode("\n", array_slice($this->active()->constraints(), 3)),
            ],
        ])->assertSessionHasNoErrors();

        $this->regenerate($this->differentReply());

        $this->assertSame('ملخص كتبه التاجر بنفسه.', $this->active()->technical['sales_summary']);
        $this->assertSame('توريد مستلزمات المقاهي', $this->active()->technical['activity_type']);

        // ويبقى له في كل توليد تالٍ، لا في الأول فقط
        $this->regenerate($this->differentReply());

        $this->assertSame('ملخص كتبه التاجر بنفسه.', $this->active()->technical['sales_summary']);
        $this->assertSame('manual', $this->active()->technicalVersions()['sales_summary']);
    }

    // ================================================================
    //  قواعد التاجر: لا يمسّها التوليد
    // ================================================================

    public function test_merchant_rules_survive_regeneration_and_reach_every_post(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput());

        $this->actingAs($this->user)->put('/brand/profile/settings', [
            'dialect' => 'saudi',
            'content_rules' => "لا تذكر أسماء المنافسين\nاختم كل منشور بدعوة لزيارة الفروع",
        ])->assertSessionHasNoErrors();

        $this->answer(['audience' => 'أصحاب المقاهي المختصة في الرياض']);
        $this->regenerate($this->differentReply());

        $system = PromptBuilder::make()->forBrand($this->brand->refresh())->system();
        $this->assertStringContainsString('- لا تذكر أسماء المنافسين', $system);
        $this->assertStringContainsString('- اختم كل منشور بدعوة لزيارة الفروع', $system);
        $this->assertSame(['لا تذكر أسماء المنافسين', 'اختم كل منشور بدعوة لزيارة الفروع'], $this->brand->content_rules);
    }

    public function test_notes_added_manually_before_this_change_move_to_rules_on_regeneration(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput());
        $generated = $this->active();

        // نسخة يدوية بالشكل القديم: ملاحظات التاجر مخلوطة بملاحظات التوليد
        BrandProfile::createVersion($this->brand, [
            'answers' => $generated->answers,
            'simple' => $generated->simple,
            'detailed' => $generated->detailed,
            'technical' => ['important_notes' => [
                ...$generated->constraints(),
                'اختم بدعوة لزيارة الفروع',
                'اذكر أننا الأفضل في السعودية',
            ]] + $generated->technical,
            'source' => ProfileSource::ManualEdit,
        ]);

        $this->regenerate($this->differentReply());

        $rules = (array) $this->brand->refresh()->content_rules;
        $this->assertContains('اختم بدعوة لزيارة الفروع', $rules, 'قاعدة التاجر نُقلت قبل أن يمسحها التوليد');
        $this->assertNotContains('اذكر أننا الأفضل في السعودية', $rules, 'ما لن يُنفَّذ لا يُنقل');
        $this->assertNotContains('اختم بدعوة لزيارة الفروع', $this->active()->constraints(), 'ولا يتكرر في الملاحظات');
    }

    // ================================================================
    //  C7 — لا تجاهل صامت
    // ================================================================

    public function test_rules_that_would_be_ignored_are_refused_with_the_reason(): void
    {
        $response = $this->actingAs($this->user)->put('/brand/profile/settings', [
            'dialect' => 'saudi',
            'content_rules' => "اذكر في كل محتوى أننا الأفضل في السعودية\nاذكر أن لدينا شحن مجاني",
        ]);

        $response->assertSessionHasErrors('content_rules');
        $messages = session('errors')->get('content_rules');

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('ادعاء مطلق', $messages[0]);
        $this->assertStringContainsString('حقائق البيع', $messages[1]);
        $this->assertNull($this->brand->refresh()->content_rules);
    }

    public function test_restrictions_are_always_accepted(): void
    {
        $this->actingAs($this->user)->put('/brand/profile/settings', [
            'dialect' => 'saudi',
            'content_rules' => "لا تذكر التوصيل\nتجنّب ذكر الأسعار\nلا تقل إننا الأفضل",
        ])->assertSessionHasNoErrors();

        $this->assertCount(3, $this->brand->refresh()->content_rules);
    }

    public function test_answers_with_an_absolute_claim_are_saved_with_a_warning(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput());

        $this->actingAs($this->user)
            ->put('/brand/profile/answers', ProfileFixtures::answers(['advantages' => "نحن الأفضل في السعودية\nخدمة أفضل من المعتاد"]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warnings', fn ($warnings) => count($warnings) === 1 && str_contains($warnings[0], '«الأفضل»'));

        $this->assertSame(['نحن الأفضل في السعودية', 'خدمة أفضل من المعتاد'], $this->brand->refresh()->selling_points);
    }

    /** صفحة الكتابة بلا حقل تعليمات حر (قرار 2026-09-22): ما يُرسل باسمه لا يصل البرومبت. */
    public function test_the_writing_page_has_no_free_instructions_to_ignore(): void
    {
        $product = Product::create(['brand_id' => $this->brand->id, 'type' => 'good', 'title' => 'سيروب', 'features' => 'سيروب فانيليا', 'is_active' => true]);
        $this->ai->replyWith(['caption' => 'سيروب فانيليا لقهوتك.', 'hashtags' => ['قهوة']]);

        $this->actingAs($this->user)->post('/content/generator', [
            'items' => [[
                'goal' => 'direct_sales', 'platform' => 'instagram', 'format' => 'image',
                'product_id' => $product->id, 'language' => 'ar', 'dialect' => 'saudi',
                'instructions' => 'قل إننا الأرخص في السوق',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('instructions', GenerationJob::sole()->payload);
    }

    // ================================================================
    //  الواجهة
    // ================================================================

    public function test_regenerating_unchanged_answers_asks_first(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput());

        $this->actingAs($this->user)->get('/brand/profile')
            ->assertSee('إجاباتك لم تتغير منذ آخر توليد')
            ->assertSee('ثابتة');

        $this->answer(['audience' => 'أصحاب المقاهي في جدة']);

        $this->actingAs($this->user)->get('/brand/profile')
            ->assertDontSee('إجاباتك لم تتغير منذ آخر توليد')
            ->assertSee('الأوصاف لا تعكس آخر تعديلاتك');
    }

    public function test_an_older_prompt_profile_says_regeneration_rewrites_everything(): void
    {
        $this->answer(reply: ProfileFixtures::modelOutput());
        $this->makeActiveLegacy(['prompt_version' => 1]);

        $this->actingAs($this->user)->get('/brand/profile')
            ->assertSee('طوّرنا طريقة كتابة الهوية منذ آخر توليد')
            ->assertDontSee('ويبقى الوصف التقني كما هو');
    }
}
