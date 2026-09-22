<?php

namespace Tests\Unit;

use App\Services\AI\Support\JsonExtractor;
use App\Services\Content\ContentSchema;
use PHPUnit\Framework\TestCase;

class ContentSchemaTest extends TestCase
{
    public function test_it_extracts_json_wrapped_in_a_code_fence(): void
    {
        $raw = "تفضل:\n```json\n{\"caption\":\"مرحباً\",\"hashtags\":[\"قهوة\"]}\n```";

        $this->assertSame('مرحباً', JsonExtractor::extract($raw)['caption']);
    }

    public function test_it_normalizes_a_carousel_and_strips_empty_slides(): void
    {
        $normalized = ContentSchema::normalize([
            'caption' => ' كابشن ',
            'slides' => [
                ['role' => 'hook', 'text' => ' الهوك '],
                ['role' => 'pull', 'text' => '   '],
                ['role' => 'ask', 'text' => 'اطلب الآن'],
            ],
            'hashtags' => ['#قهوة', 'قهوة', 'مقاهي'],
        ], 'carousel');

        $this->assertCount(2, $normalized['slides']);
        $this->assertSame('كابشن', $normalized['caption']);
        $this->assertSame(['قهوة', 'مقاهي'], $normalized['hashtags']);
    }

    /** ما يُرسم على الصورة هو ما يُفحص ويُنشر: نص الهوك مجموع طبقاته. */
    public function test_hook_layers_become_the_slide_text_and_visuals_are_kept(): void
    {
        $normalized = ContentSchema::normalize(['slides' => [
            ['role' => 'hook', 'text' => 'نص آخر كتبه النموذج', 'kicker' => ' قهوتك خيبت أملك؟ ', 'focal' => 'السر', 'tail' => 'في الطحنة', 'visual' => 'Beans'],
            ['role' => 'pull', 'text' => 'نطحنها لأداتك.', 'kicker' => 'تُتجاهل', 'focal' => 'لغير الهوك'],
            ['role' => 'unknown', 'text' => 'دور غير معروف'],
        ]], 'carousel');

        $this->assertSame('قهوتك خيبت أملك؟ السر في الطحنة', $normalized['slides'][0]['text']);
        $this->assertSame('السر', $normalized['slides'][0]['focal']);
        $this->assertSame('Beans', $normalized['slides'][0]['visual']);
        $this->assertSame(['role' => 'pull', 'text' => 'نطحنها لأداتك.'], $normalized['slides'][1]);
        $this->assertSame('pull', $normalized['slides'][2]['role']);
    }

    public function test_latin_letters_glued_to_an_arabic_hashtag_are_dropped(): void
    {
        $normalized = ContentSchema::normalize(['caption' => 'x', 'hashtags' => ['#بن_الديرةSend', 'قهوة_V60', 'coffee']], 'post');

        $this->assertSame(['بن_الديرة', 'قهوة_V60', 'coffee'], $normalized['hashtags']);
    }

    public function test_a_carousel_with_too_few_slides_is_not_usable(): void
    {
        $normalized = ContentSchema::normalize(['slides' => [['role' => 'hook', 'text' => 'واحدة']]], 'carousel');

        $this->assertFalse(ContentSchema::isUsable($normalized, 'carousel'));
    }
}
