<?php

namespace Tests\Unit;

use App\Services\Brand\Quality\ProfileQualityCheck;
use App\Services\Brand\Quality\ProfileQualityReport;
use Tests\TestCase;
use Tests\Support\ProfileFixtures;

/**
 * كل اختبار يفسد شيئاً واحداً في ملف سليم ويتأكد أن الفحص يلتقطه بعينه.
 */
class ProfileQualityCheckTest extends TestCase
{
    protected function check(array $profile = [], array $answers = [], array $banned = []): ProfileQualityReport
    {
        return (new ProfileQualityCheck)->check(
            $this->merge(ProfileFixtures::profile(), $profile),
            ProfileFixtures::answers($answers),
            $banned,
        );
    }

    /**
     * دمج عميق، لكن القائمة تُستبدل كاملة: array_replace_recursive يدمج القوائم
     * بالفهرس، فاستبدال ثلاث ملاحظات بواحدة كان يُبقي الاثنتين الأخريين.
     */
    protected function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null)
                ? $this->merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    public function test_a_well_formed_profile_passes_clean(): void
    {
        $report = $this->check();

        $this->assertSame([], $report->issues(), json_encode($report->issues(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(100, $report->score());
    }

    // ===================== الاختلاق =====================

    public function test_an_invented_year_is_an_error(): void
    {
        $report = $this->check(['detailed' => ProfileFixtures::detailed().' تأسست الشركة منذ 2016.']);

        $this->assertTrue($report->has('invented_number'));
        $this->assertFalse($report->passes());
    }

    public function test_arabic_indic_digits_are_caught_too(): void
    {
        $this->assertTrue($this->check(['simple' => 'امدادات القهوة تخدم المقاهي منذ ٢٠١٦.'])->has('invented_number'));
    }

    public function test_an_invented_phone_number_is_an_error(): void
    {
        $this->assertTrue($this->check(['detailed' => ProfileFixtures::detailed().' للطلب: 0551234567'])->has('invented_number'));
    }

    public function test_a_number_given_by_the_user_is_allowed(): void
    {
        $report = $this->check(
            ['detailed' => ProfileFixtures::detailed().' ولدى الشركة 15 فرعاً.'],
            ['advantages' => "لدينا 15 فرعاً\nتوصيل مجاني"],
        );

        $this->assertFalse($report->has('invented_number'));
    }

    public function test_an_invented_link_is_an_error_but_the_given_one_is_fine(): void
    {
        $this->assertTrue($this->check(['simple' => ProfileFixtures::simple().' زوروا www.coffee-sa.com'])->has('invented_link'));

        // نفس النطاق بمسار مختلف ليس اختلاقاً
        $this->assertFalse($this->check(['simple' => ProfileFixtures::simple().' https://coffeesupplies.com.sa/ar/offers'])->has('invented_link'));
    }

    // ===================== القيود =====================

    public function test_a_banned_word_is_an_error(): void
    {
        $report = $this->check(['simple' => ProfileFixtures::simple().' أسعار رخيصة للجميع.'], [], ['رخيص']);

        $this->assertTrue($report->has('banned_word'));
        $this->assertFalse($report->passes());
    }

    /** تدقيق المنافس (H3): «ليصل المنتج مناسباً» أوحت بتوصيل لم يذكره أحد. */
    public function test_an_implied_service_that_was_never_answered_is_an_error(): void
    {
        $answers = ['advantages' => 'طحن حسب طريقة التحضير عند الطلب'];

        $report = $this->check(['detailed' => ProfileFixtures::detailed().' ليصل المنتج مناسباً لاستخدامه.'], $answers);
        $this->assertTrue($report->has('unsupported_claim'));

        // امدادات القهوة ذكرت التوصيل في إجاباتها: ذكره في الوصف ليس اختلاقاً
        $this->assertFalse($this->check()->has('unsupported_claim'));
    }

    /** المطابقة الحرفية كانت تُفلت «بتجربتك» من حظر «تجربة» (تدقيق المنافس C8). */
    public function test_a_banned_word_is_caught_in_its_inflections(): void
    {
        $report = $this->check(['simple' => ProfileFixtures::simple().' نصمم لك بتجربتك الخاصة.'], [], ['تجربة']);

        $this->assertTrue($report->has('banned_word'));
        $this->assertStringContainsString('بتجربتك', $report->errors()[0]['message']);
    }

    public function test_banned_words_are_checked_in_the_notes_as_well(): void
    {
        $report = $this->check([], [], ['الجملة']);

        $this->assertContains('important_notes', array_column($report->errors(), 'field'));
    }

    public function test_a_superlative_is_a_warning_with_its_origin(): void
    {
        $report = $this->check(['simple' => 'امدادات القهوة الأفضل لمستلزمات المقاهي.']);

        $this->assertTrue($report->has('superlative'));
        $this->assertTrue($report->passes(), 'المبالغة تحذير لا خطأ');
        $this->assertStringContainsString('لم ترد في الإجابات', $report->warnings()[0]['message'] ?? implode(' ', array_column($report->warnings(), 'message')));
    }

    public function test_a_superlative_inside_a_longer_word_is_not_flagged(): void
    {
        // «أفضلية» ليست «أفضل»
        $this->assertFalse($this->check(['simple' => ProfileFixtures::simple().' مع أفضلية للطلبات المتكررة.'])->has('superlative'));
    }

    public function test_a_generic_cliche_is_a_warning_not_an_error(): void
    {
        $report = $this->check(['simple' => 'امدادات القهوة وجهة متكاملة تقدم لك تجربة فريدة لمستلزمات المقاهي.']);

        $cliches = collect($report->issues())->where('code', 'cliche');

        $this->assertCount(2, $cliches, json_encode($report->issues(), JSON_UNESCAPED_UNICODE));
        $this->assertTrue($report->passes(), 'العبارة المستهلكة تحذير لا خطأ');
    }

    public function test_a_cliche_the_merchant_wrote_is_not_flagged(): void
    {
        // «جودة عالية» ميزته كما كتبها: القرار له، لا لنا
        $answers = ['advantages' => 'جودة عالية'];

        $this->assertFalse($this->check(['simple' => ProfileFixtures::simple().' بجودة عالية.'], $answers)->has('cliche'));
        $this->assertTrue($this->check(['simple' => ProfileFixtures::simple().' بجودة عالية.'])->has('cliche'));
    }

    public function test_the_cliche_list_is_read_from_config(): void
    {
        config(['brand.profile_cliches' => ['مورد واحد']]);

        $this->assertTrue($this->check()->has('cliche'), 'القائمة من الإعدادات وحدها: تعديلها يغيّر الفحص بلا نشر كود');
    }

    // ===================== الشكل =====================

    public function test_placeholder_or_code_leftovers_are_errors(): void
    {
        $this->assertTrue($this->check(['simple' => '[نص تجريبي · simple]'])->has('placeholder'));
        $this->assertTrue($this->check(['technical' => ['sales_summary' => 'Array']])->has('placeholder'));
    }

    public function test_markdown_is_a_warning(): void
    {
        $this->assertTrue($this->check(['simple' => '**امدادات القهوة** وجهة متكاملة لمستلزمات الكافيهات.'])->has('markdown'));
    }

    public function test_english_text_is_flagged_but_a_latin_brand_name_is_not(): void
    {
        $this->assertTrue($this->check(['simple' => 'Coffee Supplies is a one-stop shop for cafes and restaurants in Saudi Arabia.'])->has('not_arabic'));

        $this->assertFalse($this->check(['simple' => ProfileFixtures::simple().' (Coffee Supplies)'])->has('not_arabic'));
    }

    // ===================== الطول =====================

    public function test_length_rules(): void
    {
        $this->assertTrue($this->check(['simple' => str_repeat('كلمة ', 80)])->has('too_long'));
        $this->assertTrue($this->check(['detailed' => 'امدادات القهوة مورد للمقاهي.'])->has('too_short'));
        $this->assertTrue($this->check(['detailed' => str_repeat('امدادات القهوة ', 150)])->has('too_long'));
    }

    public function test_a_small_overshoot_is_tolerated(): void
    {
        // 65 كلمة: فوق الستين بقليل وداخل الهامش
        $this->assertFalse($this->check(['simple' => 'امدادات القهوة '.str_repeat('كلمة ', 63)])->has('too_long'));
    }

    public function test_truncation_and_line_breaks_are_warnings(): void
    {
        $this->assertTrue($this->check(['detailed' => ProfileFixtures::detailed().' ويمكن…'])->has('truncated'));
        $this->assertTrue($this->check(['simple' => "امدادات القهوة وجهة متكاملة.\n\nتخدم المقاهي."])->has('not_one_paragraph'));
    }

    // ===================== المحتوى =====================

    public function test_the_project_name_must_appear_regardless_of_hamza(): void
    {
        $this->assertTrue($this->check([
            'simple' => 'وجهة متكاملة لمستلزمات الكافيهات والمطاعم.',
            'detailed' => str_repeat('نوفر مستلزمات المقاهي والمطاعم بأنواعها. ', 20),
        ])->has('name_missing'));

        // «إمدادات» بهمزة و«امدادات» بدونها اسم واحد
        $this->assertFalse($this->check([], ['project_name' => 'إمدادات القهوة'])->has('name_missing'));
    }

    public function test_notes_rules(): void
    {
        $this->assertTrue($this->check([], [], [])->passes());

        $few = $this->check(['technical' => ['important_notes' => ['ركّز على المقاهي.']]]);
        $this->assertTrue($few->has('notes_count'));

        $dupes = $this->check(['technical' => ['important_notes' => ['ركّز على المقاهي.', 'ركز على المقاهي.', 'اذكر الجملة.']]]);
        $this->assertTrue($dupes->has('duplicate_notes'));
    }

    public function test_an_empty_technical_field_is_an_error(): void
    {
        $report = $this->check(['technical' => ['sales_summary' => '']]);

        $this->assertTrue($report->has('missing_field'));
        $this->assertFalse($report->passes());
    }
}
