<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AI\AiManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'المدير', 'email' => 'admin@example.com', 'password' => 'secret123',
        ]);
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'text_provider' => 'anthropic',
            'image_provider' => 'fake',
            'anthropic_api_key' => 'sk-ant-test-1234567890abcdef',
            'anthropic_model' => 'claude-sonnet-4-5',
        ], $overrides);
    }

    public function test_admin_sees_settings_page(): void
    {
        $this->actingAs($this->admin)->get('/settings/ai')
            ->assertOk()
            ->assertSee('مفتاح API')
            ->assertSee('Anthropic');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::create(['name' => 'عميل', 'email' => 'user@example.com', 'password' => 'secret123']);

        $this->actingAs($user)->get('/settings/ai')->assertForbidden();
        $this->actingAs($user)->put('/settings/ai', $this->payload())->assertForbidden();
        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_key_is_stored_encrypted_and_applied_to_ai_manager(): void
    {
        $this->actingAs($this->admin)->put('/settings/ai', $this->payload())
            ->assertRedirect('/settings/ai');

        $stored = PlatformSetting::find('anthropic.api_key');
        $this->assertNotSame('sk-ant-test-1234567890abcdef', $stored->value);
        $this->assertTrue($stored->is_secret);

        $driver = app(AiManager::class)->text();
        $this->assertSame('anthropic', $driver->name());
        $this->assertSame('sk-ant-test-1234567890abcdef', config('ai.providers.anthropic.api_key'));
    }

    public function test_page_never_renders_the_full_key(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => []])]);

        $this->actingAs($this->admin)->put('/settings/ai', $this->payload());

        $this->actingAs($this->admin)->get('/settings/ai')
            ->assertOk()
            ->assertDontSee('sk-ant-test-1234567890abcdef')
            ->assertSee('sk-ant');
    }

    public function test_blank_key_keeps_existing_and_clear_removes_it(): void
    {
        $this->actingAs($this->admin)->put('/settings/ai', $this->payload());
        $this->actingAs($this->admin)->put('/settings/ai', $this->payload(['anthropic_api_key' => '']));

        $this->assertNotNull(PlatformSetting::find('anthropic.api_key'));

        $this->actingAs($this->admin)->put('/settings/ai', $this->payload([
            'anthropic_api_key' => '',
            'clear' => ['anthropic.api_key'],
        ]));

        $this->assertNull(PlatformSetting::find('anthropic.api_key'));
    }

    public function test_switching_provider_rebuilds_cached_driver(): void
    {
        $ai = app(AiManager::class);
        $this->assertSame('fake', $ai->text()->name());

        $this->actingAs($this->admin)->put('/settings/ai', $this->payload());

        $this->assertSame('anthropic', $ai->text()->name());
    }

    public function test_model_menu_lists_live_models_and_flags_retired_one(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['models' => [
                ['name' => 'models/gemini-3.6-flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-3.8-flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-3.1-flash-image', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-embedding-001', 'supportedGenerationMethods' => ['embedContent']],
                ['name' => 'models/gemini-3.6-flash-tts', 'supportedGenerationMethods' => ['generateContent']],
            ]]),
        ]);

        $this->actingAs($this->admin)->put('/settings/ai', $this->payload([
            'gemini_api_key' => 'AIza-test-key',
            'gemini_model' => 'gemini-2.5-flash',
        ]));

        $html = $this->actingAs($this->admin)->get('/settings/ai')
            ->assertOk()
            ->assertSee('gemini-3.8-flash')
            ->assertSee('gemini-3.1-flash-image')
            ->assertDontSee('gemini-embedding-001')
            ->assertSee('gemini-2.5-flash — غير متاح لحسابك')
            ->getContent();

        // نموذج النطق لا يكتب منشوراً: غائب عن قائمة النصوص، وحاضر في قسم التعليق الصوتي
        $menu = fn (string $id) => preg_match('/<select id="'.$id.'".*?<\/select>/s', $html, $m) ? $m[0] : '';

        $this->assertStringNotContainsString('gemini-3.6-flash-tts', $menu('gemini_model_select'));
        $this->assertStringContainsString('gemini-3.6-flash-tts', $menu('voiceover_hd_model_select'));
        $this->assertStringNotContainsString('gemini-3.8-flash"', $menu('voiceover_hd_model_select'));
    }

    public function test_value_equal_to_default_is_not_pinned(): void
    {
        $this->actingAs($this->admin)->put('/settings/ai', $this->payload([
            'gemini_model' => config('ai.providers.gemini.model'),
        ]));

        $this->assertNull(PlatformSetting::find('gemini.model'));
    }

    public function test_rejects_unknown_provider(): void
    {
        $this->actingAs($this->admin)->put('/settings/ai', $this->payload(['text_provider' => 'evil']))
            ->assertSessionHasErrors('text_provider');
    }

    public function test_connection_test_reports_success(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-sonnet-4-5',
                'content' => [['type' => 'text', 'text' => 'OK']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 1],
            ]),
        ]);

        $this->actingAs($this->admin)->put('/settings/ai', $this->payload());

        $this->actingAs($this->admin)->from('/settings/ai')
            ->post('/settings/ai/test', ['provider' => 'anthropic'])
            ->assertRedirect('/settings/ai')
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'sk-ant-test-1234567890abcdef'));
    }

    public function test_connection_test_explains_retired_model(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 404]], 404)]);

        $this->actingAs($this->admin)->put('/settings/ai', $this->payload(['gemini_api_key' => 'AIza-test-key']));

        $this->actingAs($this->admin)->from('/settings/ai')
            ->post('/settings/ai/test', ['provider' => 'gemini'])
            ->assertSessionHasErrors('provider');

        $this->assertStringContainsString(
            'النموذج «gemini-3.6-flash» غير متاح لحسابك',
            session('errors')->first('provider')
        );
    }

    public function test_connection_test_reports_failure(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'invalid x-api-key'], 401)]);

        $this->actingAs($this->admin)->put('/settings/ai', $this->payload());

        $this->actingAs($this->admin)->from('/settings/ai')
            ->post('/settings/ai/test', ['provider' => 'anthropic'])
            ->assertSessionHasErrors('provider');
    }
}
