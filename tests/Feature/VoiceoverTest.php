<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\AI\Support\Wav;
use App\Services\Settings\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * التعليق الصوتي (Beta): الصفحة، والتوليد بمساره الملزم (طابور + AiManager + حجز وتسوية)،
 * وحارس أدوات النص الذي يمنع تغيير كلمات التاجر، والسجل (حفظ/حذف/عزل المستأجر).
 */
class VoiceoverTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'ai.speech_provider' => 'fake',
            'ai.text_provider' => 'fake',
            'ai.media_disk' => 'public',
        ]);

        $this->user = User::create(['name' => 'ريم', 'email' => 'voice@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'dialect' => 'saudi',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function item(array $overrides = []): array
    {
        return $overrides + [
            'text' => 'ليش قهوتك أحياناً تكون مرّة بزيادة؟ غالباً السبب يرجع لنوع البن اللي تستخدمه.',
            'voice' => 'hadeel',
            'style' => 'reels',
            'language' => 'ar',
            'dialect' => 'saudi',
            'variant' => 'najdi',
            'tier' => 'hd',
        ];
    }

    protected function generate(array $items)
    {
        return $this->actingAs($this->user)->postJson(route('voiceover.store'), ['items' => $items]);
    }

    /** نموذج نص يرد بما نحدده بالترتيب، ويحتفظ بما وصله. */
    protected function fakeText(array $replies): object
    {
        $fake = new class(app(AiSettings::class), $replies) extends AiManager
        {
            public array $seen = [];

            public function __construct(AiSettings $settings, public array $replies)
            {
                parent::__construct($settings);
            }

            public function generateText(TextRequest $request, ?GenerationJob $job = null, ?string $provider = null): TextResponse
            {
                $this->seen[] = $request;
                $reply = array_shift($this->replies);

                return new TextResponse(raw: json_encode(['text' => $reply]), data: ['text' => $reply], provider: 'fake', model: 'fake');
            }
        };

        $this->app->instance(AiManager::class, $fake);

        return $fake;
    }

    // ------------------------------------------------------------------
    // الصفحة
    // ------------------------------------------------------------------

    public function test_page_renders_with_beta_badge_voices_and_plan_items(): void
    {
        ContentItem::create([
            'brand_id' => $this->brand->id,
            'goal' => 'engagement',
            'platform' => 'instagram',
            'format' => 'reel',
            'language' => 'ar',
            'options' => ['dialect' => 'saudi'],
            'body' => ['scenes' => [
                ['time' => '0-3', 'voiceover' => 'ليه الطعم القديم تغيّر بلمسة واحدة؟', 'on_screen' => 'لمسة واحدة'],
                ['time' => '3-8', 'voiceover' => 'جرّب نكهة المستكة #قهوة ☕'],
            ]],
            'caption' => 'كابشن لا يُقرأ',
            'status' => 'ready',
            'in_plan' => true,
        ]);

        $response = $this->actingAs($this->user)->get(route('voiceover.index'))->assertOk();

        $response->assertSee('التعليق الصوتي')
            ->assertSee('Beta')
            ->assertSee('نمط الإلقاء')
            ->assertSee('ريلز وتيك توك')
            ->assertSee('Marketerai 3.1')
            ->assertSee('Marketerai 2.3')
            ->assertDontSee('Sahalai')
            ->assertSee('نجدية (الرياض)');

        $studio = $response->viewData('studio');

        $this->assertCount(18, $studio['voices']);
        $this->assertArrayNotHasKey('provider_voice', $studio['voices'][0], 'اسم الصوت عند المزود تفصيل تقني لا يصل للواجهة');
        $this->assertCount(1, $studio['planItems']);
        // الكلام وحده: بلا نص الشاشة ولا الهاشتاق ولا الإيموجي ولا الكابشن
        $this->assertSame("ليه الطعم القديم تغيّر بلمسة واحدة؟\nجرّب نكهة المستكة", $studio['planItems'][0]['text']);
        $this->assertSame('زيادة تفاعل', $studio['planItems'][0]['goal_label']);
    }

    public function test_sidebar_lists_voiceover_under_content_creation_with_beta(): void
    {
        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('voiceover.index'), $html);
        $this->assertMatchesRegularExpression('/التعليق الصوتي<\/span>\s*<span class="sidebar-text nav-badge">Beta<\/span>/u', $html);
    }

    // ------------------------------------------------------------------
    // التوليد
    // ------------------------------------------------------------------

    public function test_generation_holds_estimate_then_settles_by_actual_duration(): void
    {
        $this->generate([$this->item()])->assertStatus(202)->assertJsonCount(1, 'jobs');

        $job = GenerationJob::withoutBrandScope()->where('type', 'voiceover')->firstOrFail();

        $this->assertSame(JobStatus::Completed, $job->status);
        // hd = 3 نقاط للدقيقة، والنص أقل من دقيقة
        $this->assertEquals(3, $job->credits_charged);
        $this->assertEquals(97, $this->brand->fresh()->credit_balance);

        $asset = MediaAsset::withoutBrandScope()->where('kind', 'audio')->firstOrFail();

        Storage::disk('public')->assertExists($asset->path);
        $this->assertTrue(Wav::isWav(Storage::disk('public')->get($asset->path)));
        $this->assertSame('hadeel', $asset->meta['voice']);
        $this->assertSame('najdi', $asset->meta['variant']);
        $this->assertGreaterThan(0, $asset->meta['duration']);
        $this->assertNull($asset->content_item_id, 'صفحة المحتوى تعرض mediaAssets صوراً؛ المصدر في meta');
        $this->assertSame([$asset->id], $job->result['media_ids']);
    }

    public function test_long_text_holds_more_minutes_and_refunds_what_was_not_spoken(): void
    {
        // 300 كلمة: التقدير 3 دقائق (120 كلمة/دقيقة) ← 9 نقاط؛ النطق الوهمي 2.5 كلمة/ث = دقيقتان ← 6
        $this->generate([$this->item(['text' => trim(str_repeat('قهوة مختصة ', 150))])])->assertStatus(202);

        $job = GenerationJob::withoutBrandScope()->where('type', 'voiceover')->firstOrFail();

        $this->assertSame(3, $job->payload['estimated_minutes']);
        $this->assertEquals(6, $job->credits_charged);
        $this->assertEquals(94, $this->brand->fresh()->credit_balance);
    }

    public function test_batch_creates_one_job_per_item(): void
    {
        $this->generate([
            $this->item(),
            $this->item(['voice' => 'khalid', 'tier' => 'standard', 'style' => 'news', 'dialect' => 'msa', 'variant' => null]),
        ])->assertStatus(202)->assertJsonCount(2, 'jobs');

        $this->assertSame(2, MediaAsset::withoutBrandScope()->where('kind', 'audio')->count());
        // 3 (hd) + 2 (standard)
        $this->assertEquals(95, $this->brand->fresh()->credit_balance);
    }

    public function test_generation_is_refused_before_holding_when_speech_is_not_configured(): void
    {
        config(['ai.speech_provider' => 'gemini', 'ai.providers.gemini.api_key' => null]);

        $this->generate([$this->item()])->assertStatus(422)->assertJsonFragment(['message' => 'التعليق الصوتي غير مفعّل بعد: يحتاج مفتاح Gemini في إعدادات الذكاء الاصطناعي.']);

        $this->assertSame(0, GenerationJob::withoutBrandScope()->count());
        $this->assertEquals(100, $this->brand->fresh()->credit_balance);
    }

    public function test_custom_style_requires_directions_and_text_limit_ignores_tags(): void
    {
        $this->generate([$this->item(['style' => 'custom'])])->assertStatus(422)->assertJsonValidationErrors('items.0.custom_style');

        // 2000 حرف كلام + وسوم أداء: مقبول لأن الوسوم لا تُحسب
        $text = str_repeat('ق', 2000).' [short pause] [excited]';
        $this->generate([$this->item(['text' => $text])])->assertStatus(202);

        $this->generate([$this->item(['text' => str_repeat('ق', 2001)])])->assertStatus(422)->assertJsonValidationErrors('items.0.text');
    }

    public function test_insufficient_credits_are_reported_without_starting(): void
    {
        $this->brand->update(['credit_balance' => 1]);

        $this->generate([$this->item()])->assertStatus(422);

        $this->assertSame(0, MediaAsset::withoutBrandScope()->count());
    }

    public function test_style_and_dialect_become_the_performance_direction(): void
    {
        $fake = new class(app(AiSettings::class)) extends AiManager
        {
            public array $seen = [];

            public function generateSpeech(\App\Services\AI\DTO\SpeechRequest $request, ?GenerationJob $job = null, ?string $provider = null): \App\Services\AI\DTO\SpeechResponse
            {
                $this->seen[] = $request;

                return parent::generateSpeech($request, $job, $provider);
            }
        };
        $this->app->instance(AiManager::class, $fake);

        $this->generate([$this->item(['style' => 'custom', 'custom_style' => 'بهدوء وثقة'])])->assertStatus(202);

        $request = $fake->seen[0];
        $this->assertSame('Kore', $request->voice);
        $this->assertSame('gemini-3.8-flash-tts', $request->model);
        $this->assertStringContainsString('«بهدوء وثقة»', $request->direction);
        $this->assertStringContainsString('Najdi', $request->direction);
    }

    // ------------------------------------------------------------------
    // أدوات النص
    // ------------------------------------------------------------------

    public function test_enhance_adds_tags_without_changing_words_and_drops_unknown_tags(): void
    {
        $this->fakeText(['[excited] ليش قهوتك مرّة؟ [short pause] [dramatic] السبب البن!']);

        $uuid = $this->actingAs($this->user)
            ->postJson(route('voiceover.tool', 'enhance'), ['text' => 'ليش قهوتك مرّة؟ السبب البن', 'style' => 'reels'])
            ->assertStatus(202)->json('uuid');

        $job = GenerationJob::where('uuid', $uuid)->firstOrFail();

        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame('[excited] ليش قهوتك مرّة؟ [short pause] السبب البن!', $job->result['text']);
    }

    public function test_enhance_that_changes_words_is_retried_then_fails_without_delivering(): void
    {
        $fake = $this->fakeText([
            'ليش قهوتك مرّة؟ السبب البن الرخيص',
            'ليش قهوتك مرّة؟ السبب البن الرخيص',
        ]);

        $uuid = $this->actingAs($this->user)
            ->postJson(route('voiceover.tool', 'enhance'), ['text' => 'ليش قهوتك مرّة؟ السبب البن'])
            ->json('uuid');

        $job = GenerationJob::where('uuid', $uuid)->firstOrFail();

        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertCount(2, $fake->seen);
        $this->assertStringContainsString('«الرخيص»', $fake->seen[1]->prompt, 'الإعادة تسمّي الكلمة المضافة');
    }

    public function test_diacritize_passes_when_only_harakat_are_added(): void
    {
        $this->fakeText(['لِيشْ قَهْوَتَكْ مُرَّة؟']);

        $uuid = $this->actingAs($this->user)
            ->postJson(route('voiceover.tool', 'diacritize'), ['text' => 'ليش قهوتك مرة؟'])
            ->json('uuid');

        $this->assertSame('لِيشْ قَهْوَتَكْ مُرَّة؟', GenerationJob::where('uuid', $uuid)->first()->result['text']);
    }

    public function test_unknown_tool_is_not_found(): void
    {
        $this->actingAs($this->user)->postJson('/voiceover/tools/translate', ['text' => 'نص'])->assertNotFound();
    }

    // ------------------------------------------------------------------
    // السجل والعينات
    // ------------------------------------------------------------------

    public function test_pin_delete_and_tenant_isolation(): void
    {
        $this->generate([$this->item()]);
        $asset = MediaAsset::withoutBrandScope()->where('kind', 'audio')->firstOrFail();

        $this->actingAs($this->user)->postJson(route('voiceover.pin', $asset))
            ->assertOk()->assertJson(['pinned' => true, 'expires' => null]);

        $other = User::create(['name' => 'خالد', 'email' => 'other-voice@example.com', 'password' => 'secret123']);
        $otherBrand = Brand::create(['user_id' => $other->id, 'name' => 'آخر', 'onboarding_completed' => true]);
        $other->update(['current_brand_id' => $otherBrand->id]);

        // كما في عملية PHP جديدة لكل طلب: لا براند سابق عالق في CurrentBrand وقت ربط المسار
        \App\Support\CurrentBrand::clear();
        $this->actingAs($other)->deleteJson(route('voiceover.destroy', $asset))->assertNotFound();
        \App\Support\CurrentBrand::clear();
        $this->actingAs($other)->postJson(route('voiceover.pin', $asset))->assertNotFound();
        $this->actingAs($other)->getJson(route('voiceover.history'))->assertJsonCount(0, 'items');

        \App\Support\CurrentBrand::clear();
        $this->actingAs($this->user)->deleteJson(route('voiceover.destroy', $asset))->assertOk();
        Storage::disk('public')->assertMissing($asset->path);
        $this->assertNull(MediaAsset::withoutBrandScope()->find($asset->id));
    }

    public function test_history_shows_retention_countdown(): void
    {
        $this->generate([$this->item()]);

        $item = $this->actingAs($this->user)->getJson(route('voiceover.history'))->assertOk()->json('items.0');

        $this->assertSame('هديل', $item['voice_name']);
        $this->assertSame('3.1', $item['tier']);
        $this->assertSame('سعودية نجدية (الرياض)', $item['language']);
        $this->assertMatchesRegularExpression('/^(29 يوم و 23 ساعة|30 يوم)$/u', $item['expires']);
    }

    public function test_sample_is_generated_once_then_served(): void
    {
        $this->actingAs($this->user)->getJson(route('voiceover.sample', 'sara'))->assertStatus(202);

        Storage::disk('public')->assertExists('voice-samples/sara-ar.wav');

        $this->actingAs($this->user)->getJson(route('voiceover.sample', 'sara'))
            ->assertOk()->assertJsonStructure(['url']);

        $this->assertSame(0, GenerationJob::withoutBrandScope()->count(), 'العينة لا تظهر في «إنتاجاتي» ولا تكلّف نقاطاً');
        $this->assertEquals(100, $this->brand->fresh()->credit_balance);

        $this->actingAs($this->user)->getJson(route('voiceover.sample', 'nobody'))->assertNotFound();
    }

    public function test_prune_deletes_expired_unpinned_audio_only(): void
    {
        $make = fn (bool $pinned, int $daysAgo) => tap(MediaAsset::withoutBrandScope()->create([
            'brand_id' => $this->brand->id, 'kind' => 'audio', 'disk' => 'public',
            'path' => "brands/{$this->brand->id}/voiceovers/".uniqid().'.wav', 'is_pinned' => $pinned,
        ]), function (MediaAsset $asset) use ($daysAgo) {
            Storage::disk('public')->put($asset->path, 'x');
            $asset->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        });

        $expired = $make(false, 31);
        $kept = $make(true, 31);
        $recent = $make(false, 5);

        $this->artisan('voiceover:prune')->assertSuccessful();

        $this->assertNull(MediaAsset::withoutBrandScope()->find($expired->id));
        Storage::disk('public')->assertMissing($expired->path);
        $this->assertNotNull(MediaAsset::withoutBrandScope()->find($kept->id));
        $this->assertNotNull(MediaAsset::withoutBrandScope()->find($recent->id));
    }
}
