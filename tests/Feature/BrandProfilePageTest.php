<?php

namespace Tests\Feature;

use App\Enums\ProductType;
use App\Enums\ProfileSource;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\GenerationJob;
use App\Models\User;
use App\Services\AI\Prompts\PromptBuilder;
use App\Services\Brand\BrandProfileGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة «هوية العلامة» الموحّدة: الإجابات مصدر واحد في أعمدة العلامة،
 * والأوصاف نسخٌ تُولَّد منها بطلب صريح.
 */
class BrandProfilePageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'محمد', 'email' => 'profile@example.com', 'password' => 'secret123',
        ]);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'امدادات القهوة',
            'industry' => 'توريد مستلزمات المقاهي',
            'audience' => 'أصحاب المقاهي',
            'description' => 'نبيع مواد ومعدات الكافيهات',
            'selling_points' => ['وكلاء لعلامات عالمية', 'توصيل مجاني'],
            'store_url' => 'https://coffeesupplies.com.sa/ar',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function answers(array $overrides = []): array
    {
        return [
            'type' => 'good',
            'project_name' => 'امدادات القهوة',
            'store_url' => 'https://coffeesupplies.com.sa/ar',
            'one_liner' => 'نبيع مواد ومعدات الكافيهات والمطاعم',
            'advantages' => "وكلاء لعدة علامات عالمية، ولدينا فروع\nتوصيل مجاني",
            'audience' => 'أصحاب المقاهي والمطاعم',
            'notes' => '',
            ...$overrides,
        ];
    }

    /**
     * أول ملف بأوصاف حقيقية. المزود الوهمي يكتب «[نص تجريبي]»، وإعادة توليد نص تجريبي
     * مجانية (ليس خطأ التاجر) — واختبارات هذا الملف عن أوصاف حقيقية تُحتسب إعادتها.
     */
    protected function generateFirst(): BrandProfile
    {
        $this->actingAs($this->user)->put('/brand/profile/answers', $this->answers());

        $profile = BrandProfile::forBrand($this->brand)->active()->firstOrFail();
        $profile->update([
            'simple' => \Tests\Support\ProfileFixtures::simple(),
            'detailed' => \Tests\Support\ProfileFixtures::detailed(),
            'quality' => ['model' => 'scripted/scripted-1'] + (array) $profile->quality,
        ]);

        return $profile->refresh();
    }

    // ===================== صفحة واحدة =====================

    public function test_the_old_setup_page_redirects_to_the_unified_page(): void
    {
        $this->actingAs($this->user)->get('/brand/setup')->assertRedirect('/brand/profile');
    }

    public function test_a_new_user_without_a_brand_reaches_the_page_instead_of_looping(): void
    {
        $newcomer = User::create(['name' => 'جديد', 'email' => 'new@example.com', 'password' => 'secret123']);

        // كان هذا الطلب يُحوَّل إلى نفسه بلا نهاية
        $this->actingAs($newcomer)->get('/brand/setup')->assertRedirect('/brand/profile');
        $this->actingAs($newcomer)->get('/brand/profile')->assertOk()->assertSee('عرّفنا بمشروعك');

        // وأي صفحة أخرى توجّهه إلى هنا
        $this->actingAs($newcomer)->get('/dashboard')->assertRedirect(route('brand.profile'));
    }

    public function test_answering_as_a_new_user_creates_the_brand_and_the_first_profile(): void
    {
        $newcomer = User::create(['name' => 'جديد', 'email' => 'new@example.com', 'password' => 'secret123']);

        $this->actingAs($newcomer)->put('/brand/profile/answers', $this->answers(['project_name' => 'مخبز الحي']))
            ->assertSessionHasNoErrors();

        $brand = $newcomer->refresh()->currentBrand;

        $this->assertNotNull($brand);
        $this->assertSame('مخبز الحي', $brand->name);
        $this->assertTrue($brand->onboarding_completed);
        $this->assertSame(1, BrandProfile::forBrand($brand)->count(), 'أول حفظ ينشئ الأوصاف مجاناً');
    }

    public function test_a_new_user_never_writes_into_a_brand_left_over_from_a_previous_request(): void
    {
        // CurrentBrand ثابت على مستوى العملية: نحاكي بقايا طلب سابق في عملية طويلة العمر
        \App\Support\CurrentBrand::set($this->brand);

        $newcomer = User::create(['name' => 'جديد', 'email' => 'leak@example.com', 'password' => 'secret123']);

        $this->actingAs($newcomer)->put('/brand/profile/answers', $this->answers(['project_name' => 'مخبز الحي']));

        $this->assertSame('امدادات القهوة', $this->brand->refresh()->name, 'علامة المستخدم الآخر لم تُمس');
        $this->assertSame('مخبز الحي', $newcomer->refresh()->currentBrand->name);
        $this->assertNotSame($this->brand->id, $newcomer->current_brand_id);
    }

    public function test_the_page_shows_every_block_of_the_reference_screens(): void
    {
        $this->generateFirst();

        $html = $this->actingAs($this->user)->get('/brand/profile')->assertOk()->getContent();

        foreach ([
            'مشروعك:', 'شعار الهوية', 'الوصف المبسط', 'الوصف التفصيلي', 'الوصف التقني',
            'نوع المشروع', 'ملخص المبيعات', 'توجيهات المزايا التنافسية', 'ملاحظات إضافية مهمة', 'رابط المتجر/الصفحة',
            'الأسئلة والإجابات', 'من جمهورك المستهدف؟', BrandProfile::UNANSWERED,
            'إعدادات الكتابة', 'اللهجة', 'كلمات ممنوعة',
            'تعديل الإجابات', 'إعادة توليد الأوصاف', 'حذف والبدء من جديد', 'سجل النسخ',
        ] as $text) {
            $this->assertStringContainsString($text, $html, "غاب «{$text}» عن الصفحة");
        }
    }

    // ===================== الإجابات: مصدر واحد =====================

    public function test_answers_are_stored_on_the_brand_itself(): void
    {
        $this->generateFirst();

        $brand = $this->brand->refresh();

        $this->assertSame(ProductType::Good, $brand->business_type);
        $this->assertSame('نبيع مواد ومعدات الكافيهات والمطاعم', $brand->description);
        $this->assertSame('أصحاب المقاهي والمطاعم', $brand->audience);
        // الفاصلة داخل الجملة جزء منها: الميزات تُفصل بالأسطر فقط
        $this->assertSame(['وكلاء لعدة علامات عالمية، ولدينا فروع', 'توصيل مجاني'], $brand->selling_points);
    }

    public function test_the_first_generation_is_free(): void
    {
        $response = $this->actingAs($this->user)->put('/brand/profile/answers', $this->answers());

        $job = GenerationJob::withoutBrandScope()->where('type', 'brand_profile')->firstOrFail();
        $response->assertRedirect(route('brand.profile', ['job' => $job->uuid]));

        $this->assertEquals(100, $this->brand->refresh()->credit_balance);
        $this->assertSame(BrandProfileGenerator::BASELINE_NOTE, BrandProfile::forBrand($this->brand)->first()->constraints()[0]);
    }

    public function test_editing_answers_later_saves_for_free_and_marks_descriptions_stale(): void
    {
        $this->generateFirst();

        $this->actingAs($this->user)->put('/brand/profile/answers', $this->answers(['one_liner' => 'نبيع حبوب قهوة مختصة']))
            ->assertRedirect(route('brand.profile'));

        $this->assertSame(1, BrandProfile::forBrand($this->brand)->count(), 'التعديل لا يولّد تلقائياً');
        $this->assertEquals(100, $this->brand->refresh()->credit_balance, 'التعديل مجاني');

        $html = $this->actingAs($this->user)->get('/brand/profile')->getContent();
        $this->assertStringContainsString('الأوصاف لا تعكس آخر تعديلاتك', $html);

        // إعادة التوليد تزيل التنبيه وتكلّف
        $this->actingAs($this->user)->post('/brand/profile/regenerate');

        $this->assertEquals(98, $this->brand->refresh()->credit_balance);
        $this->assertStringNotContainsString(
            'الأوصاف لا تعكس آخر تعديلاتك',
            $this->actingAs($this->user)->get('/brand/profile')->getContent(),
        );
    }

    public function test_writing_settings_save_without_generation_or_staleness(): void
    {
        $this->generateFirst();

        $this->actingAs($this->user)->put('/brand/profile/settings', [
            'dialect' => 'gulf',
            'tone' => 'رسمي ودود',
            'banned_words' => "رخيص\nالأفضل",
            'whatsapp' => '966500000000',
        ])->assertSessionHasNoErrors();

        $brand = $this->brand->refresh();

        $this->assertSame('gulf', $brand->dialect);
        $this->assertSame(['رخيص', 'الأفضل'], $brand->banned_words);
        $this->assertSame(1, BrandProfile::forBrand($brand)->count());
        $this->assertFalse(BrandProfile::forBrand($brand)->active()->first()->isStaleFor($brand));
    }

    public function test_facts_are_read_live_so_a_corrected_link_reaches_every_version(): void
    {
        $profile = $this->generateFirst();

        $this->actingAs($this->user)->put('/brand/profile/answers', $this->answers(['store_url' => 'https://new.example.com']));

        $this->assertSame('https://new.example.com', $profile->refresh()->technicalFor($this->brand->refresh())['store_url']);
        $this->assertStringContainsString('https://new.example.com', $profile->toPromptFragment());
    }

    public function test_regeneration_is_refused_without_enough_credits(): void
    {
        $this->generateFirst();
        $this->brand->update(['credit_balance' => 1]);

        $this->actingAs($this->user)->post('/brand/profile/regenerate')->assertSessionHasErrors('credits');

        $this->assertSame(1, BrandProfile::forBrand($this->brand)->count());
    }

    // ===================== التحرير اليدوي =====================

    public function test_a_manual_edit_leaves_the_generated_version_intact(): void
    {
        $generated = $this->generateFirst();

        $this->actingAs($this->user)->put('/brand/profile', ['field' => 'simple', 'simple' => 'وصف كتبته بنفسي'])
            ->assertSessionHasNoErrors();

        $active = BrandProfile::forBrand($this->brand)->active()->firstOrFail();

        $this->assertSame(2, $active->version);
        $this->assertSame(ProfileSource::ManualEdit, $active->source);
        $this->assertNotSame('وصف كتبته بنفسي', $generated->refresh()->simple, 'نسخة الذكاء لم تُمس');

        $this->actingAs($this->user)->put('/brand/profile', ['field' => 'detailed', 'detailed' => 'تفصيل يدوي']);

        $this->assertSame(2, BrandProfile::forBrand($this->brand)->count(), 'التعديل الثاني في مكانه');
        $this->assertSame('تفصيل يدوي', $active->refresh()->detailed);
    }

    /**
     * ما يكتبه التاجر في الملاحظات قاعدة منه: تنتقل إلى قواعده فلا تمسحها
     * إعادة التوليد، والقيود الثابتة تبقى ولو حذفها من النص.
     */
    public function test_technical_notes_are_saved_one_per_line(): void
    {
        $this->generateFirst();

        $this->actingAs($this->user)->put('/brand/profile', [
            'field' => 'technical',
            'technical' => [
                'activity_type' => 'بيع مواد الكافيهات',
                'sales_summary' => 'سيروبات وصوصات',
                'advantages_directives' => 'ابرز التوصيل المجاني',
                'important_notes' => "لا تذكر الأسعار\n\nركّز على المقاهي",
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            BrandProfileGenerator::FIXED_NOTES,
            BrandProfile::forBrand($this->brand)->active()->first()->constraints(),
        );

        $this->assertSame(['لا تذكر الأسعار', 'ركّز على المقاهي'], $this->brand->refresh()->content_rules);
    }

    // ===================== السجل =====================

    public function test_restoring_brings_back_descriptions_only(): void
    {
        $this->generateFirst();
        $this->actingAs($this->user)->put('/brand/profile', ['field' => 'simple', 'simple' => 'نسخة يدوية']);
        $this->actingAs($this->user)->put('/brand/profile/answers', $this->answers(['project_name' => 'امدادات القهوة الجديدة']));

        $this->actingAs($this->user)->post('/brand/profile/versions/1/restore')->assertSessionHasNoErrors();

        $active = BrandProfile::forBrand($this->brand)->active()->firstOrFail();
        $this->assertSame(3, $active->version);
        $this->assertSame(ProfileSource::Restored, $active->source);

        // الإجابات والحقائق لم ترجع للخلف
        $this->assertSame('امدادات القهوة الجديدة', $this->brand->refresh()->name);
        $this->assertSame('امدادات القهوة الجديدة', $active->technicalFor($this->brand)['project_name']);
    }

    public function test_the_active_version_cannot_be_deleted(): void
    {
        $this->generateFirst();
        $this->actingAs($this->user)->put('/brand/profile', ['field' => 'simple', 'simple' => 'يدوية']);

        $this->actingAs($this->user)->delete('/brand/profile/versions/2')->assertSessionHasErrors('version');
        $this->actingAs($this->user)->delete('/brand/profile/versions/1')->assertSessionHasNoErrors();

        $this->assertSame([2], BrandProfile::forBrand($this->brand)->pluck('version')->all());
    }

    public function test_another_brand_cannot_touch_my_versions(): void
    {
        $this->generateFirst();

        $intruder = User::create(['name' => 'دخيل', 'email' => 'x@example.com', 'password' => 'secret123']);
        $other = Brand::create(['user_id' => $intruder->id, 'name' => 'أخرى', 'onboarding_completed' => true]);
        $intruder->update(['current_brand_id' => $other->id]);

        $this->actingAs($intruder)->post('/brand/profile/versions/1/restore')->assertNotFound();
        $this->assertSame(1, BrandProfile::forBrand($this->brand)->count());
    }

    public function test_starting_over_requires_the_name_and_keeps_the_answers(): void
    {
        $this->generateFirst();

        $this->actingAs($this->user)->delete('/brand/profile', ['confirm_name' => 'اسم خاطئ'])
            ->assertSessionHasErrors('confirm_name');
        $this->assertSame(1, BrandProfile::forBrand($this->brand)->count());

        $this->actingAs($this->user)->delete('/brand/profile', ['confirm_name' => 'امدادات القهوة'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, BrandProfile::forBrand($this->brand)->count());
        $this->assertSame('أصحاب المقاهي والمطاعم', $this->brand->refresh()->audience, 'الإجابات بيانات العلامة: تبقى');
    }

    // ===================== الأثر على المحتوى =====================

    public function test_the_prompt_reads_each_fact_once(): void
    {
        $this->generateFirst();

        $prompt = PromptBuilder::make()->forBrand($this->brand->refresh())->prompt();

        $this->assertStringContainsString('نوع المشروع: سلع', $prompt);
        $this->assertStringContainsString('الجمهور المستهدف: أصحاب المقاهي والمطاعم', $prompt);

        // الرابط كان يُذكر مرتين: «المتجر:» من العلامة و«رابط المتجر/الصفحة:» من الملف
        $this->assertSame(1, substr_count($prompt, 'https://coffeesupplies.com.sa/ar'));
        $this->assertStringNotContainsString('نبذة:', $prompt);
        $this->assertStringNotContainsString('نقاط القوة:', $prompt);
    }

    public function test_profile_constraints_reach_the_system_layer(): void
    {
        $this->generateFirst();

        $this->assertStringContainsString(
            BrandProfileGenerator::BASELINE_NOTE,
            PromptBuilder::make()->forBrand($this->brand->refresh())->system(),
        );
    }
}
