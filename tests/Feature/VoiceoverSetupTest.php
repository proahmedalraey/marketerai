<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\SpeechRequest;
use App\Services\AI\DTO\SpeechResponse;
use App\Services\AI\ProviderException;
use App\Services\AI\Support\Wav;
use App\Services\Settings\AiSettings;
use App\Services\Voiceover\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تجهيز التعليق الصوتي: إعداداته في /settings/ai، وصور المذيعين، ورسالة فشل عينة «استمع».
 */
class VoiceoverSetupTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::create(['name' => 'المدير', 'email' => 'voice-admin@example.com', 'password' => 'secret123']);
        $this->admin->forceFill(['is_admin' => true])->save();

        $brand = Brand::create(['user_id' => $this->admin->id, 'name' => 'متجر', 'onboarding_completed' => true, 'credit_balance' => 50]);
        $this->admin->update(['current_brand_id' => $brand->id]);
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'text_provider' => 'fake',
            'image_provider' => 'fake',
            'speech_provider' => 'gemini',
            'gemini_api_key' => 'AIza-test-key-1234567890',
            'voiceover_standard_model' => 'gemini-3.1-flash-tts-preview',
            'voiceover_hd_model' => 'gemini-3.8-flash-tts',
        ], $overrides);
    }

    public function test_settings_page_shows_the_voiceover_section(): void
    {
        $this->actingAs($this->admin)->get('/settings/ai')
            ->assertOk()
            ->assertSee('التعليق الصوتي')
            ->assertSee('مزود الصوت')
            ->assertSee('Marketerai 2.3')
            ->assertSee('Marketerai 3.1')
            ->assertSee('10</strong> طلبات نطق يومياً', false);
    }

    public function test_tier_models_saved_in_settings_reach_the_catalog(): void
    {
        $this->actingAs($this->admin)->put('/settings/ai', $this->payload())->assertRedirect('/settings/ai');

        $this->assertSame('gemini-3.1-flash-tts-preview', PlatformSetting::find('voiceover.standard_model')->value);

        // عامل طابور جديد: القيمة من قاعدة البيانات لا من الذاكرة
        config(['voiceover.tiers.standard.model' => 'env-default-model']);
        app()->forgetInstance(AiSettings::class);
        app()->forgetInstance(VoiceCatalog::class);

        $this->assertSame('gemini-3.1-flash-tts-preview', app(VoiceCatalog::class)->tier('standard')['model']);
        $this->assertSame('gemini', config('ai.speech_provider'));
    }

    public function test_old_form_without_speech_fields_keeps_the_saved_choice(): void
    {
        $this->actingAs($this->admin)->put('/settings/ai', $this->payload())->assertRedirect();
        $this->actingAs($this->admin)->put('/settings/ai', ['text_provider' => 'fake', 'image_provider' => 'fake'])->assertRedirect();

        $this->assertSame('gemini-3.1-flash-tts-preview', PlatformSetting::find('voiceover.standard_model')?->value);
    }

    public function test_speech_test_explains_the_free_daily_quota(): void
    {
        $this->actingAs($this->admin)->put('/settings/ai', $this->payload())->assertRedirect();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'You exceeded your current quota',
                'details' => [['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']]]],
            ],
        ], 429)]);

        $this->actingAs($this->admin)->from('/settings/ai')
            ->post('/settings/ai/test', ['kind' => 'speech', 'tier' => 'hd'])
            ->assertRedirect('/settings/ai')
            ->assertSessionHasErrors('speech');

        $error = session('errors')->first('speech');

        $this->assertStringContainsString('Marketerai 3.1 (gemini-3.8-flash-tts)', $error);
        $this->assertStringContainsString('انتهت الحصة اليومية لهذا النموذج', $error);
        $this->assertStringContainsString('فعّل الفوترة في Google AI Studio', $error);
    }

    public function test_speech_test_reports_success_with_the_tier_model(): void
    {
        $this->actingAs($this->admin)->put('/settings/ai', $this->payload())->assertRedirect();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;rate=24000', 'data' => base64_encode(str_repeat("\0", 24000))]]]], 'finishReason' => 'STOP']],
            'modelVersion' => 'gemini-3.1-flash-tts-preview',
        ])]);

        $this->actingAs($this->admin)->from('/settings/ai')
            ->post('/settings/ai/test', ['kind' => 'speech', 'tier' => 'standard'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Marketerai 2.3') && str_contains($s, 'gemini-3.1-flash-tts-preview'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/models/gemini-3.1-flash-tts-preview:generateContent'));
    }

    public function test_avatars_command_writes_a_small_jpeg_the_catalog_serves(): void
    {
        $public = storage_path('framework/testing/public-'.uniqid());
        File::ensureDirectoryExists($public);
        $this->app->usePublicPath($public);

        config(['ai.image_provider' => 'fake']);

        $this->artisan('voiceover:avatars', ['--voice' => 'khalid'])->assertSuccessful();

        $file = "{$public}/images/voices/khalid.jpg";
        $this->assertFileExists($file);
        $this->assertLessThanOrEqual(320, getimagesize($file)[0]);

        // الرسم المرفق مع الكود احتياط الصورة، والصورة الفوتوغرافية تسبقه
        File::put("{$public}/images/voices/khalid.svg", '<svg/>');
        File::put("{$public}/images/voices/sara.svg", '<svg/>');

        $cards = collect(app(VoiceCatalog::class)->voiceCards())->keyBy('key');
        $this->assertStringContainsString('images/voices/khalid.jpg?v=', $cards['khalid']['avatar']);
        $this->assertStringContainsString('images/voices/sara.svg?v=', $cards['sara']['avatar']);
        $this->assertNull($cards['reem']['avatar'], 'بلا صورة ولا رسم: الحرف الأول');

        File::deleteDirectory($public);
    }

    public function test_sample_failure_reason_is_shown_instead_of_waiting(): void
    {
        config(['ai.speech_provider' => 'fake']);

        $this->app->instance(AiManager::class, new class(app(AiSettings::class)) extends AiManager
        {
            public function generateSpeech(SpeechRequest $request, ?GenerationJob $job = null, ?string $provider = null): SpeechResponse
            {
                throw new ProviderException('quota', 'gemini', 429, quotaExhausted: true);
            }
        });

        // الطابور متزامن في الاختبارات: المهمة تفشل داخل الطلب الأول
        $this->actingAs($this->admin)->getJson(route('voiceover.sample', 'noura'))->assertStatus(202);

        $this->actingAs($this->admin)->getJson(route('voiceover.sample', 'noura'))
            ->assertStatus(422)
            ->assertJson(['message' => 'عينة هذا المذيع لم تُجهَّز بعد: انتهت حصة مزود الصوت اليومية. جرّب لاحقاً.']);

        Storage::disk('public')->assertMissing('voice-samples/noura-ar.wav');
    }

    public function test_overlong_sample_is_rejected_rather_than_saved(): void
    {
        config(['ai.speech_provider' => 'fake']);

        $this->app->instance(AiManager::class, new class(app(AiSettings::class)) extends AiManager
        {
            public int $calls = 0;

            public function generateSpeech(SpeechRequest $request, ?GenerationJob $job = null, ?string $provider = null): SpeechResponse
            {
                $this->calls++;
                // جملة العينة ~7 ث؛ 18 ث = قرأ التوجيه (كما حدث مع عينة أحمد)
                $audio = Wav::fromPcm(str_repeat("\0", 18 * 16000), 8000);

                return new SpeechResponse(audio: $audio, durationSeconds: 18.0, provider: 'fake', model: 'fake');
            }
        });

        $this->artisan('voiceover:samples', ['--voice' => 'ahmed'])->assertFailed();

        $this->assertSame(2, app(AiManager::class)->calls);
        Storage::disk('public')->assertMissing('voice-samples/ahmed-ar.wav');
    }
}
