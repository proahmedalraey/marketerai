<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\Brand\BrandProfileGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProfileFixtures;
use Tests\TestCase;

/**
 * الهوية تُولَّد بنموذجها الخاص إن خُصّص (config('ai.profile'))،
 * وباقي التوليدات تبقى على المزود والنموذج الافتراضيين.
 */
class ProfileModelRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // قبل أول تطبيق للإعدادات: هذه تصير «قيم البيئة» التي يرجع إليها
        config([
            'ai.text_provider' => 'gemini',
            'ai.providers.gemini.api_key' => 'gemini-key',
            'ai.providers.openai.api_key' => 'openai-key',
            'ai.providers.openai.model' => 'gpt-4o-mini',
            'ai.providers.anthropic.api_key' => null,
            'ai.retry.sleep_ms' => 1,
        ]);
    }

    protected function draft(): array
    {
        // علامة غير محفوظة كما في brand:eval-profile: نفس مسار الإنتاج بلا بيانات
        return app(BrandProfileGenerator::class)->draft(
            new Brand(['name' => 'امدادات القهوة', 'dialect' => 'saudi']),
            ProfileFixtures::answers(),
        );
    }

    protected function openAi(string $model): array
    {
        return [
            'model' => $model,
            'choices' => [['message' => ['content' => json_encode(ProfileFixtures::modelOutput(), JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
        ];
    }

    protected function gemini(): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode(ProfileFixtures::modelOutput(), JSON_UNESCAPED_UNICODE)]]]]]];
    }

    public function test_the_profile_goes_to_its_own_provider_and_model(): void
    {
        config(['ai.profile.provider' => 'openai', 'ai.profile.model' => 'gpt-4.1']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAi('gpt-4.1'))]);

        $draft = $this->draft();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com') && $r['model'] === 'gpt-4.1');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'googleapis.com'));
        $this->assertSame('openai/gpt-4.1', $draft['model']);
        $this->assertSame(ProfileFixtures::simple(), $draft['simple']);
    }

    public function test_without_a_profile_model_the_provider_model_is_used(): void
    {
        config(['ai.profile.provider' => 'openai']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAi('gpt-4o-mini'))]);

        $this->draft();

        Http::assertSent(fn (Request $r) => $r['model'] === 'gpt-4o-mini');
    }

    public function test_a_model_alone_applies_to_the_default_provider(): void
    {
        // كإعداد OpenRouter: المزود الافتراضي نفسه، بنموذج آخر للهوية وحدها
        config(['ai.text_provider' => 'openai', 'ai.profile.model' => 'gpt-4.1']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAi('gpt-4.1'))]);

        $this->draft();

        Http::assertSent(fn (Request $r) => $r['model'] === 'gpt-4.1');
    }

    public function test_a_rejected_profile_model_falls_back_to_the_provider_model(): void
    {
        config(['ai.profile.provider' => 'openai', 'ai.profile.model' => 'gpt-typo']);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'model not found']], 404)
            ->push($this->openAi('gpt-4o-mini'))]);

        $draft = $this->draft();

        $models = Http::recorded()->map(fn ($pair) => $pair[0]['model'])->all();

        $this->assertSame(['gpt-typo', 'gpt-4o-mini'], $models);
        $this->assertSame(ProfileFixtures::simple(), $draft['simple']);
    }

    public function test_a_profile_provider_without_a_key_falls_back_to_the_default(): void
    {
        // ملف بالنموذج الافتراضي خير من مهمة فاشلة
        config(['ai.profile.provider' => 'anthropic', 'ai.profile.model' => 'claude-opus-5-5']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->gemini())]);

        $this->draft();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'googleapis.com'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic.com'));
    }

    public function test_other_generations_keep_the_default_model_on_the_same_provider(): void
    {
        // المزود نفسه بنموذجين: لا يختلط المبني لأحدهما بالآخر
        config(['ai.text_provider' => 'openai', 'ai.profile.provider' => 'openai', 'ai.profile.model' => 'gpt-4.1']);
        Http::fake(['api.openai.com/*' => Http::response($this->openAi('any'))]);

        $this->draft();
        app(AiManager::class)->generateText(new TextRequest('system', 'prompt'));

        $models = Http::recorded()->map(fn ($pair) => $pair[0]['model'])->all();

        $this->assertSame(['gpt-4.1', 'gpt-4o-mini'], $models);
    }

    public function test_without_a_profile_provider_nothing_changes(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->gemini())]);

        $this->draft();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'googleapis.com'));
    }
}
