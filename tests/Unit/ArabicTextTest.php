<?php

namespace Tests\Unit;

use App\Services\Import\ProductEnricher;
use App\Support\Arabic\ArabicText;
use App\Support\WordDiff;
use PHPUnit\Framework\TestCase;

/**
 * المطابِق يلتقط تصريفات الكلمة، ولا يلتقط كلمة أخرى تشاركها حروفاً.
 * الخطأ في الاتجاه الثاني أسوأ: طلب تصحيح لنص سليم، وتنبيه يعلّم التاجر تجاهل التنبيهات.
 */
class ArabicTextTest extends TestCase
{
    public function test_it_finds_a_word_through_prefixes_suffixes_and_broken_plurals(): void
    {
        $this->assertSame(
            ['تجربة', 'التجربة', 'بتجربتك', 'تجاربنا', 'وتجارب'],
            ArabicText::find('تجربة · التجربة · بتجربتك · تجاربنا · وتجارب العملاء', 'تجربة'),
        );
    }

    public function test_it_ignores_diacritics_and_hamza_forms(): void
    {
        $this->assertSame(['مجّاناً'], ArabicText::find('نطحنها لك مجّاناً', 'مجاني'));
        $this->assertTrue(ArabicText::contains('إمدادات القهوة', 'امدادات'));
        $this->assertSame('75', ArabicText::digits('٧٥'));
    }

    /** @return array<string, array{string, string}> */
    public static function lookalikes(): array
    {
        return [
            'كوب ليس كوبون' => ['كوب قهوة واحد يكفي', 'كوبون'],
            'اتصل ليس توصيلاً' => ['اتصل بنا الآن', 'تصل'],
            'تواصل ليس توصيلاً' => ['تواصل معنا', 'تصل'],
            'لا حصر لها ليست حصرية' => ['خيارات لا حصر لها', 'حصريا'],
        ];
    }

    /** @dataProvider lookalikes */
    public function test_a_word_sharing_letters_is_not_a_match(string $text, string $target): void
    {
        $this->assertSame([], ArabicText::find($text, $target));
    }

    public function test_unstemmed_matching_keeps_msa_words_apart_from_dialect_words(): void
    {
        $this->assertSame([], ArabicText::find('حين تبدأ يومك', 'الحين', stem: false));
        $this->assertSame(['والحين'], ArabicText::find('والحين وقتها', 'الحين', stem: false));
    }

    public function test_phrases_match_with_the_article_on_any_word(): void
    {
        $this->assertNotEmpty(ArabicText::find('الكمية المحدودة تنفد', 'كمية محدودة'));
        $this->assertNotEmpty(ArabicText::find('انضم إلى آلاف العملاء', 'آلاف العملاء'));
    }

    public function test_word_diff_names_the_exact_correction(): void
    {
        $spelling = WordDiff::compare('هل تشعر أن صباحك أصلح مكرراً', 'هل تشعر أن صباحك أصبح مكرراً');

        $this->assertSame(1, $spelling['changed']);
        $this->assertSame([['before' => 'أصلح', 'after' => 'أصبح']], $spelling['changes']);

        // تدقيق المنافس C9: «مع حدد» تركيب مكسور
        $grammar = WordDiff::compare('واطلب عبوتك الحين مع حدد طحنتك', 'واطلب عبوتك الحين وحدد طحنتك');

        $this->assertSame([['before' => 'مع حدد', 'after' => 'وحدد']], $grammar['changes']);
        $this->assertSame(0, WordDiff::compare("سطر\nثانٍ", 'سطر ثانٍ')['changed']);
    }

    public function test_repeated_bullets_are_dropped_but_distinct_ones_stay(): void
    {
        $summary = "• مطحنة يدوية للاستخدام المنزلي.\n• المنتج مطحنة يدوية منزلية.\n• تصميم صغير يسهل حمله.";

        $this->assertSame(
            "• مطحنة يدوية للاستخدام المنزلي.\n• تصميم صغير يسهل حمله.",
            ProductEnricher::dropRepeatedBullets($summary),
        );
    }
}
