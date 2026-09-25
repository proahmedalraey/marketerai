<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\ModelCatalog;
use App\Services\AI\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterProviderTest extends TestCase
{
    use RefreshDatabase;

    protected const BASE = 'openrouter.ai/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'ai.text_provider' => 'openrouter',
            'ai.image_provider' => 'openrouter',
            'ai.providers.openrouter.api_key' => 'sk-or-v1-test',
            'ai.retry.times' => 0,
            'app.name' => 'Marketer Ai',
            'app.url' => 'https://marketer.test',
        ]);
    }

    protected function completion(string $content, array $extra = []): array
    {
        return array_replace_recursive([
            'model' => 'google/gemini-3.6-flash',
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 9, 'cost' => 0.000123],
        ], $extra);
    }

    public function test_text_sends_identity_headers_and_records_real_cost(): void
    {
        Http::fake([self::BASE.'/chat/completions' => Http::response($this->completion('{"caption":"قهوة"}'))]);

        $response = app(AiManager::class)->generateText(new TextRequest(
            system: 'اكتب', prompt: 'قهوة', schema: ['caption' => 'string'],
        ));

        $this->assertSame('قهوة', $response->data['caption']);
        $this->assertSame(0.000123, $response->costUsd);
        $this->assertSame('openrouter', $response->provider);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-or-v1-test')
            && $r->hasHeader('HTTP-Referer', 'https://marketer.test')
            && $r->hasHeader('X-OpenRouter-Title', 'Marketer Ai')
            && $r['model'] === 'google/gemini-3.6-flash'
            && $r['response_format'] === ['type' => 'json_object']
            && $r['reasoning'] === ['effort' => 'low']);
    }

    public function test_option_the_model_rejects_is_retried_without_it(): void
    {
        Http::fake([
            self::BASE.'/chat/completions' => Http::sequence()
                ->push(['error' => ['code' => 400, 'message' => 'reasoning is not supported by this model']], 400)
                ->push($this->completion('نص')),
        ]);

        $response = app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p'));

        $this->assertSame('نص', $response->raw);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => ! isset($r['reasoning']) && ! isset($r['response_format']));
    }

    public function test_insufficient_credit_is_quota_exhausted_and_not_retried(): void
    {
        config(['ai.retry.times' => 3]);

        Http::fake([self::BASE.'/chat/completions' => Http::response(['error' => ['code' => 402, 'message' => 'Insufficient credits']], 402)]);

        try {
            app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p'));
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ProviderException $e) {
            $this->assertTrue($e->quotaExhausted);
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('نفدت الحصة', $e->userMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_error_inside_a_200_response_is_raised(): void
    {
        Http::fake([self::BASE.'/chat/completions' => Http::response([
            'error' => ['code' => 502, 'message' => 'Upstream provider failed'],
        ])]);

        try {
            app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p'));
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ProviderException $e) {
            $this->assertSame(502, $e->statusCode);
            $this->assertTrue($e->retryable);
        }
    }

    public function test_truncated_reply_gets_a_merchant_message(): void
    {
        Http::fake([self::BASE.'/chat/completions' => Http::response($this->completion('', [
            'choices' => [['finish_reason' => 'length']],
        ]))]);

        try {
            app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p'));
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('انقطع رد الذكاء الاصطناعي', $e->userMessage());
        }
    }

    protected function fakeImageModels(): void
    {
        Http::fake([
            self::BASE.'/images/models' => Http::response(['data' => [
                [
                    'id' => 'google/gemini-3.1-flash-image',
                    'supported_parameters' => [
                        'resolution' => ['type' => 'enum', 'values' => ['512', '1K', '2K', '4K']],
                        'aspect_ratio' => ['type' => 'enum', 'values' => ['1:1', '4:5', '9:16', '16:9']],
                        'input_references' => ['type' => 'range', 'min' => 0, 'max' => 14],
                    ],
                ],
                [
                    'id' => 'no-reference/model',
                    'supported_parameters' => [
                        'aspect_ratio' => ['type' => 'enum', 'values' => ['1:1']],
                        'quality' => ['type' => 'enum', 'values' => ['low', 'medium', 'high']],
                    ],
                ],
            ]]),
            self::BASE.'/images' => Http::response([
                'data' => [['b64_json' => base64_encode('PNGDATA'), 'media_type' => 'image/png']],
                'usage' => ['cost' => 0.04],
            ]),
        ]);
    }

    public function test_image_sends_only_what_the_model_supports(): void
    {
        $this->fakeImageModels();

        $response = app(AiManager::class)->generateImage(new ImageRequest(
            prompt: 'كوب قهوة', aspectRatio: '4:5', quality: 'high_2k', count: 2,
        ));

        $this->assertSame(2, $response->count());
        $this->assertSame('PNGDATA', $response->images[0]->contents);
        $this->assertSame(0.08, $response->costUsd);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/images')
            && $r['aspect_ratio'] === '4:5'
            && $r['resolution'] === '2K'
            // gemini-image لا يعلن quality، فلا نرسلها
            && ! isset($r['quality'])
            && $r->hasHeader('X-OpenRouter-Title', 'Marketer Ai'));
    }

    public function test_several_images_are_requested_in_parallel_and_summed(): void
    {
        $this->fakeImageModels();

        $response = app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x', count: 3));

        $this->assertSame(3, $response->count());
        $this->assertEqualsWithDelta(0.12, $response->costUsd, 0.0001);
        Http::assertSentCount(4); // كتالوج القدرات + 3 صور
    }

    public function test_a_failed_image_does_not_discard_the_ones_that_succeeded(): void
    {
        $ok = ['data' => [['b64_json' => base64_encode('PNGDATA'), 'media_type' => 'image/png']], 'usage' => ['cost' => 0.04]];

        Http::fake([
            self::BASE.'/images/models' => Http::response(['data' => []]),
            self::BASE.'/images' => Http::sequence()
                ->push($ok)
                ->push(['error' => ['message' => 'Provider overloaded']], 503)
                ->push($ok),
        ]);

        $response = app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x', count: 3));

        // صورتان دُفع ثمنهما عند المزود: تصلان للمستخدم والخدمة تُرجع نقاط الثالثة
        $this->assertSame(2, $response->count());
        $this->assertEqualsWithDelta(0.08, $response->costUsd, 0.0001);
    }

    public function test_when_every_image_fails_the_first_error_is_thrown(): void
    {
        Http::fake([
            self::BASE.'/images/models' => Http::response(['data' => []]),
            self::BASE.'/images' => Http::response(['error' => ['message' => 'Provider blocked by content moderation']], 400),
        ]);

        try {
            app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x', count: 2));
            $this->fail('كان يجب أن يُرمى خطأ');
        } catch (ProviderException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertTrue($e->isModeration());
        }
    }

    public function test_an_unreachable_host_is_retried_and_then_succeeds(): void
    {
        config(['ai.retry.times' => 2, 'ai.retry.sleep_ms' => 0]);

        Http::fake([
            self::BASE.'/images/models' => Http::response(['data' => []]),
            self::BASE.'/images' => Http::sequence()
                ->pushFailedConnection('cURL error 6: Could not resolve host: openrouter.ai')
                ->push(['data' => [['b64_json' => base64_encode('PNGDATA'), 'media_type' => 'image/png']], 'usage' => ['cost' => 0.04]]),
        ]);

        $response = app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x'));

        $this->assertSame(1, $response->count());
        $this->assertCount(2, Http::recorded(fn ($r) => str_ends_with($r->url(), '/images'))); // فاشلة ثم ناجحة
    }

    public function test_a_persistent_dns_failure_ends_with_a_clear_message(): void
    {
        config(['ai.retry.times' => 2, 'ai.retry.sleep_ms' => 0]);

        Http::fake([
            self::BASE.'/images/models' => Http::response(['data' => []]),
            self::BASE.'/images' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: openrouter.ai'),
        ]);

        try {
            app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x'));
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ProviderException $e) {
            $this->assertTrue($e->retryable, 'يُعاد من الطابور أيضاً');
            $this->assertStringContainsString('اتصال الإنترنت', ProviderException::messageFor($e));
        }
    }

    public function test_a_timeout_is_not_retried_and_says_so(): void
    {
        config(['ai.retry.times' => 2, 'ai.retry.sleep_ms' => 0]);

        Http::fake([
            self::BASE.'/images/models' => Http::response(['data' => []]),
            self::BASE.'/images' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out after 180000 milliseconds'),
        ]);

        try {
            app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x'));
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ConnectionException $e) {
            // قد يكون المزود نفّذ الطلب فعلاً: إعادته تدفع ثمنين
            $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/images')));
            $this->assertStringContainsString('استغرق الرد', ProviderException::messageFor($e));
        }
    }

    public function test_a_reference_that_cannot_be_downloaded_fails_clearly(): void
    {
        $this->fakeImageModels();
        Http::fake(['cdn.salla.sa/*' => Http::response('Forbidden', 403)] + []);

        try {
            app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x', referenceImage: 'https://cdn.salla.sa/p.jpg'));
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('تعذّر تنزيل صورة المنتج المرجعية', $e->userMessage());
        }

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/images'));
    }

    public function test_quality_is_sent_to_models_that_declare_it(): void
    {
        $this->fakeImageModels();
        config(['ai.providers.openrouter.image_model' => 'no-reference/model']);

        app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x', quality: 'high_1k'));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/images')
            && $r['quality'] === 'high' && ! isset($r['resolution']));
    }

    public function test_reference_image_becomes_a_data_url(): void
    {
        $this->fakeImageModels();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $path = tempnam(sys_get_temp_dir(), 'ref').'.png';
        file_put_contents($path, $png);

        app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x', referenceImage: $path));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/images')
            && str_starts_with($r['input_references'][0]['image_url']['url'], 'data:image/png;base64,'));

        @unlink($path);
    }

    public function test_reference_image_on_a_model_without_support_fails_clearly(): void
    {
        $this->fakeImageModels();
        config(['ai.providers.openrouter.image_model' => 'no-reference/model']);

        try {
            app(AiManager::class)->generateImage(new ImageRequest(prompt: 'x', referenceImage: __FILE__));
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('لا يدعم صورة المنتج المرجعية', $e->userMessage());
        }

        // لم يُرسل أي طلب توليد: لا نصرف رصيداً على صورة لمنتج متخيَّل
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/images'));
    }

    public function test_catalog_keeps_live_text_models_only_and_needs_no_key(): void
    {
        config(['ai.providers.openrouter.api_key' => null]);

        Http::fake([
            self::BASE.'/models' => Http::response(['data' => [
                ['id' => 'old/model', 'created' => 100, 'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['response_format']],
                ['id' => 'new/model', 'created' => 900, 'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['structured_outputs']],
                ['id' => 'new/model:batch', 'created' => 950, 'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['response_format']],
                ['id' => 'no/json', 'created' => 800, 'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['temperature']],
                ['id' => 'img/out', 'created' => 700, 'architecture' => ['output_modalities' => ['text', 'image']], 'supported_parameters' => ['response_format']],
            ]]),
            self::BASE.'/images/models' => Http::response(['data' => [
                ['id' => 'google/gemini-3.1-flash-image', 'supported_parameters' => []],
            ]]),
        ]);

        $catalog = app(ModelCatalog::class)->for('openrouter');

        $this->assertSame(['new/model', 'old/model'], $catalog['text']);
        $this->assertSame(['google/gemini-3.1-flash-image'], $catalog['image']);
    }

    public function test_failed_image_index_is_not_cached(): void
    {
        Http::fake([self::BASE.'/images/models' => Http::sequence()
            ->push('boom', 500)
            ->push(['data' => [['id' => 'a/b', 'supported_parameters' => ['aspect_ratio' => []]]]])]);

        $catalog = app(ModelCatalog::class);

        $this->assertNull($catalog->imageParameters('a/b'));
        $this->assertNotNull($catalog->imageParameters('a/b'));
    }

    public function test_settings_page_shows_openrouter_with_search_and_saves_settings(): void
    {
        $admin = User::create(['name' => 'المدير', 'email' => 'admin@example.com', 'password' => 'secret123']);
        $admin->forceFill(['is_admin' => true])->save();

        $many = collect(range(1, 60))->map(fn ($i) => [
            'id' => "vendor/model-{$i}", 'created' => $i,
            'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['response_format'],
        ])->all();

        Http::fake([
            self::BASE.'/models' => Http::response(['data' => $many]),
            self::BASE.'/images/models' => Http::response(['data' => [['id' => 'google/gemini-3.1-flash-image', 'supported_parameters' => []]]]),
        ]);

        $this->actingAs($admin)->put('/settings/ai', [
            'text_provider' => 'openrouter',
            'image_provider' => 'openrouter',
            'openrouter_api_key' => 'sk-or-v1-secret-key-value',
            'openrouter_model' => '~anthropic/claude-sonnet-latest',
        ])->assertRedirect('/settings/ai');

        $this->assertSame('~anthropic/claude-sonnet-latest', PlatformSetting::find('openrouter.model')->value);
        $this->assertNotSame('sk-or-v1-secret-key-value', PlatformSetting::find('openrouter.api_key')->value);

        $this->actingAs($admin)->get('/settings/ai')
            ->assertOk()
            ->assertSee('OpenRouter')
            // القائمة تُضمَّن كـ JSON فتُهرَّب الشرطة المائلة؛ يكفي وجود آخر عنصر
            ->assertSee('model-60')
            ->assertSee('ابحث في النماذج')
            ->assertDontSee('sk-or-v1-secret-key-value');

        $this->assertSame('openrouter', app(AiManager::class)->text()->name());
    }
}
