<?php

namespace Tests\Unit;

use App\Services\Content\ContentFacts;
use App\Services\Content\Quality\ContentQualityCheck;
use App\Services\Quality\QualityReport;
use Tests\TestCase;

/**
 * أخطاء المنافس اختبارات انحدار عندنا.
 *
 * كل نص هنا منقول من تدقيق «سهل AI» (sahal-audit-raw-notes.md، القسم 3)
 * على نفس المنتج ونفس الإعدادات، وكل منها يجب أن يُلتقط.
 * المرجع: docs/sahal-audit-plan.md، جدول فحوص المرحلة 1.
 */
class ContentQualityCheckTest extends TestCase
{
    /** بيانات «بن الديرة» والمنتج (أ) كما أُدخلت في التدقيق. */
    protected function deiraFacts(string $extra = ''): ContentFacts
    {
        return new ContentFacts(implode("\n", [
            '## العلامة التجارية',
            'الاسم: اختبار-بن الديرة',
            'نوع النشاط: حبوب قهوة مختصة محمصة وأدوات تحضير منزلية',
            'توجيهات المزايا التنافسية: طحن القهوة حسب طريقة التحضير عند الطلب',
            'الجمهور المستهدف: محبو القهوة المختصة في المنزل',
            '## المنتج',
            'الاسم: حبوب يرقاشيفي إثيوبية',
            'المميزات: درجة تحميص فاتحة، نكهات الياسمين والليمون والتوت',
            'المواصفات: الوزن: 250 جرام',
            'السعر: 75.00 SAR',
            $extra,
        ]));
    }

    protected function check(string $caption, array $options = [], ?ContentFacts $facts = null, array $extra = []): QualityReport
    {
        return (new ContentQualityCheck)->check(
            ['caption' => $caption, 'slides' => [], 'hashtags' => [], ...$extra],
            $facts ?? $this->deiraFacts(),
            ['platform' => 'instagram', 'format' => 'post', 'dialect' => 'saudi', ...$options],
        );
    }

    protected function assertCaught(QualityReport $report, string $code, string $needle): void
    {
        $messages = collect($report->issues())->where('code', $code)->pluck('message');

        $this->assertTrue(
            $messages->contains(fn ($m) => str_contains($m, $needle)),
            "لم يُلتقط «{$needle}» بالرمز {$code}. المرصود: ".json_encode($report->issues(), JSON_UNESCAPED_UNICODE),
        );
    }

    // ================================================================
    //  C4·V2 — زاوية المقارنة السعرية: أرقام مخترعة
    // ================================================================

    public function test_it_catches_the_invented_market_price_and_yield(): void
    {
        $report = $this->check(
            'لماذا تدفع 20 ريالاً يومياً لكوب قهوة عادي؟ عبوة 250 جرام بـ 75 ريالاً تكفيك 15 كوباً، أي 5 ريالات فقط للكوب الواحد.'
        );

        $this->assertCaught($report, 'invented_number', '«20»');
        $this->assertCaught($report, 'invented_number', '«15»');
        $this->assertCaught($report, 'invented_number', '«5»');
        $this->assertFalse($report->passes());

        // السعر والوزن من البيانات: لا يُبلَّغ عنهما
        $this->assertStringNotContainsString('«75»', json_encode($report->issues(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('«250»', json_encode($report->issues(), JSON_UNESCAPED_UNICODE));
    }

    public function test_it_catches_the_invented_free_grinding_and_mistransliterated_tools(): void
    {
        $report = $this->check('ونطحنها لك مجاناً لتناسب أداتك (في 60، أسبيرو، كيمكس).');

        $this->assertCaught($report, 'unsupported_claim', 'مجاناً');
        $this->assertCaught($report, 'invented_number', '«60»');
        $this->assertCaught($report, 'transliterated_name', 'AeroPress');
        $this->assertCaught($report, 'transliterated_name', 'Chemex');
    }

    // ================================================================
    //  C4·V3 — زاوية الدليل الاجتماعي: بشر مخترعون
    // ================================================================

    public function test_it_catches_invented_ratings_customer_counts_and_percentages(): void
    {
        $report = $this->check(
            'أعادت الثقة لـ 14,200+ محب للقهوة. تقييم 4.9/5 من أكثر من 3,500 تقييم موثق، وأكثر من 98% من عملائنا كرروا الشراء.'
        );

        $this->assertCaught($report, 'invented_number', '14,200');
        $this->assertCaught($report, 'invented_rating', '4.9/5');
        $this->assertCaught($report, 'invented_number', '3,500');
        $this->assertCaught($report, 'invented_number', '98%');
        $this->assertCaught($report, 'invented_testimonial', 'تقييم موثق');
    }

    public function test_it_catches_a_fully_invented_customer_testimonial(): void
    {
        $report = $this->check(
            '«كنت أظن القهوة المختصة حامضة ومُرّة حتى جربت يرقاشيفي من بن الديرة» — عبدالمجيد، عميل موثق'
        );

        $this->assertCaught($report, 'invented_testimonial', 'عميل موثق');
    }

    public function test_a_quoted_testimonial_without_the_verified_label_is_caught_too(): void
    {
        $report = $this->check('«أفضل قهوة شربتها في حياتي، صارت روتيني كل صباح» — سارة');

        $this->assertTrue($report->has('invented_testimonial'));
    }

    public function test_crowd_claims_written_in_words_are_caught(): void
    {
        $this->assertTrue($this->check('انضم إلى آلاف العملاء الذين غيّروا صباحهم.')->has('invented_customers'));
    }

    // ================================================================
    //  C6 — المناسبة: كود خصم مخترع
    // ================================================================

    public function test_it_catches_an_invented_discount_code(): void
    {
        $report = $this->check(
            'اطلب يرقاشيفي بـ 75 ريالاً واستخدم كود KSA96 للحصول على خصم اليوم الوطني 🎁',
            facts: $this->deiraFacts('## المناسبة: اليوم الوطني السعودي 96'),
        );

        $this->assertCaught($report, 'invented_code', 'KSA96');
        $this->assertCaught($report, 'unsupported_claim', 'خصم');
    }

    public function test_a_real_code_from_the_merchant_is_allowed(): void
    {
        $report = $this->check(
            'استخدم كود KSA96 للحصول على خصم اليوم الوطني.',
            facts: $this->deiraFacts('تعليمات: اذكر كود الخصم KSA96 بخصم 15% حتى نهاية سبتمبر'),
        );

        $this->assertFalse($report->has('invented_code'));
        $this->assertFalse($report->has('unsupported_claim'));
    }

    // ================================================================
    //  C1 و C8 — كلمة ممنوعة بكل تصريفاتها، وتوصيل لم يُذكر
    // ================================================================

    public function test_banned_word_is_caught_in_every_inflection(): void
    {
        $report = $this->check(
            'اكتشف تجربة مختلفة. التجربة الأولى تغيّر صباحك، وبتجربتك ستعرف لماذا تتكرر تجاربنا.',
            ['banned_words' => ['تجربة', 'رخيص']],
        );

        $this->assertCaught($report, 'banned_word', 'بتجربتك');
        $this->assertCaught($report, 'banned_word', 'تجاربنا');
        $this->assertCount(1, collect($report->issues())->where('code', 'banned_word'), 'كلمة ممنوعة واحدة = مشكلة واحدة تسرد صيغها');
    }

    public function test_it_catches_delivery_that_the_merchant_never_mentioned(): void
    {
        $report = $this->check('نطحنها لك عند الطلب وتصلك بطزاجة مطلقة.');

        $this->assertCaught($report, 'unsupported_claim', 'وتصلك');
        $this->assertCaught($report, 'puffery', 'مطلقة');
    }

    public function test_delivery_is_allowed_when_the_merchant_offers_it(): void
    {
        $facts = new ContentFacts(implode("\n", [
            'الاسم: امدادات القهوة',
            'المزايا: وكلاء لعدة علامات تجارية عالمية، ولدينا عدة فروع ومناديب توصيل مجاني للانشطة التجارية في نفس المدينة',
            'المنتج: حشوة كريمة البندق البيضاء من بيسان، الوزن 5 كجم، السعر 120 ريال',
        ]));

        $report = $this->check('حشوة بيسان 5 كجم بـ 120 ريالاً، والتوصيل مجاني لمقهاك داخل المدينة. نحن وكلاء معتمدون.', facts: $facts);

        $this->assertFalse($report->has('unsupported_claim'), json_encode($report->issues(), JSON_UNESCAPED_UNICODE));
        $this->assertTrue($report->passes());
    }

    public function test_medical_claims_are_rejected_even_when_the_merchant_writes_them(): void
    {
        $report = $this->check('قهوة تقوي المناعة وتعالج الصداع.', facts: $this->deiraFacts('ملاحظة: القهوة تعالج الصداع'));

        $this->assertTrue($report->has('unsupported_claim'));
    }

    // ================================================================
    //  C3 و C9 — تسرّب اللهجة
    // ================================================================

    public function test_colloquial_words_in_msa_are_flagged(): void
    {
        $report = $this->check('ملّيت من القهوة العادية؟ الحين وقت التغيير.', ['dialect' => 'msa']);

        $this->assertCaught($report, 'dialect_leak', 'ملّيت');
        $this->assertCaught($report, 'dialect_leak', 'الحين');
        $this->assertTrue($report->passes(), 'تسرّب اللهجة تحذير لا خطأ');
    }

    public function test_egyptian_words_in_saudi_text_are_flagged_but_saudi_words_are_not(): void
    {
        $report = $this->check('زهقت من الروتين؟ الحين عندنا الحل عشان صباحك.', ['dialect' => 'saudi']);

        $this->assertCaught($report, 'dialect_leak', 'زهقت');
        $this->assertCount(1, collect($report->issues())->where('code', 'dialect_leak'));
    }

    public function test_msa_word_sharing_a_root_with_a_dialect_word_is_not_flagged(): void
    {
        $this->assertFalse($this->check('حين تبدأ يومك بكوب متقن.', ['dialect' => 'msa'])->has('dialect_leak'));
    }

    // ================================================================
    //  C4·V1 — المخرج النظيف يجب أن يمر
    // ================================================================

    public function test_a_clean_sensory_post_passes(): void
    {
        $report = $this->check(
            "صباحك يبدأ برائحة الياسمين.\nحبوب يرقاشيفي الإثيوبية بتحميص فاتح تكشف نكهات الليمون والتوت في كوبك.\n250 جرام بـ 75 ريالاً، ونطحنها حسب طريقة تحضيرك عند الطلب.",
            ['banned_words' => ['تجربة']],
            extra: ['hashtags' => ['قهوة_مختصة', 'يرقاشيفي']],
        );

        $this->assertSame([], $report->issues(), json_encode($report->issues(), JSON_UNESCAPED_UNICODE));
    }

    public function test_small_counts_that_structure_the_post_are_not_claims(): void
    {
        $report = $this->check("3 خطوات لكوب متقن:\n1. اطحن عند الطلب\n2. اضبط الماء\n3. استمتع");

        $this->assertFalse($report->has('invented_number'));
    }

    public function test_numbers_from_the_occasion_and_hashtags_are_checked_against_the_facts(): void
    {
        $facts = $this->deiraFacts('## المناسبة: اليوم الوطني السعودي 96');

        $this->assertFalse($this->check('نحتفل معكم', facts: $facts, extra: ['hashtags' => ['اليوم_الوطني_96']])->has('invented_number'));
        $this->assertTrue($this->check('نحتفل معكم', extra: ['hashtags' => ['اليوم_الوطني_96']])->has('invented_number'));
    }

    // ================================================================
    //  البنية
    // ================================================================

    public function test_x_posts_over_280_characters_are_errors(): void
    {
        $report = $this->check(str_repeat('قهوة مختصة ', 30), ['platform' => 'x']);

        $this->assertTrue($report->has('too_long'));
    }

    public function test_slides_are_checked_and_located(): void
    {
        $report = (new ContentQualityCheck)->check(
            ['caption' => 'اطلب الآن', 'slides' => [
                ['role' => 'hook', 'text' => 'هل مللت الروتين؟'],
                ['role' => 'pull', 'text' => 'تكفيك العبوة 15 كوباً'],
            ], 'hashtags' => []],
            $this->deiraFacts(),
            ['platform' => 'instagram', 'format' => 'carousel', 'slides' => 6],
        );

        $this->assertSame('slide:2', collect($report->issues())->firstWhere('code', 'invented_number')['field']);
        $this->assertTrue($report->has('slide_count'));
        $this->assertSame('الشريحة 2', ContentQualityCheck::fieldLabel('slide:2'));
    }

    public function test_the_fake_provider_output_is_flagged_as_a_placeholder(): void
    {
        $this->assertTrue($this->check('[كابشن تجريبي] المنصة تعمل.')->has('placeholder'));
    }
}
