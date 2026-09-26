<?php

namespace Tests\Feature;

use App\Services\AI\AiManager;
use App\Services\Content\Proofreader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProofreadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.text_provider' => 'openrouter',
            'ai.providers.openrouter.api_key' => 'sk-or-v1-test',
            'ai.providers.openrouter.model' => 'main/model',
            'ai.retry.times' => 0,
        ]);
    }

    protected function reply(string $json): array
    {
        return [
            'model' => 'x',
            'choices' => [['message' => ['content' => $json], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ];
    }

    protected function models(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]['model'])->all();
    }

    public function test_proofreading_uses_its_own_model_and_leaves_the_main_one_alone(): void
    {
        config(['ai.proofread.model' => 'fast/proofreader']);
        Http::fake(['openrouter.ai/*' => Http::response($this->reply('{"caption":"أصبح"}'))]);

        $result = app(Proofreader::class)->proofread(['caption' => 'أصلح'], 'saudi');

        $this->assertSame(['fast/proofreader'], $this->models());
        $this->assertSame('أصبح', $result['content']['caption']);
        // الدرايفر المخزَّن للتوليد الرئيس لم يتأثر بالنسخة المؤقتة
        $this->assertSame('main/model', app(AiManager::class)->text()->model());
    }

    public function test_without_a_proofread_model_the_main_one_is_used(): void
    {
        config(['ai.proofread.model' => null]);
        Http::fake(['openrouter.ai/*' => Http::response($this->reply('{"caption":"نص"}'))]);

        app(Proofreader::class)->proofread(['caption' => 'نص'], 'saudi');

        $this->assertSame(['main/model'], $this->models());
    }

    public function test_a_bad_proofread_model_falls_back_instead_of_losing_the_proofread(): void
    {
        config(['ai.proofread.model' => 'typo/model']);
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push(['error' => ['code' => 404, 'message' => 'No endpoints found']], 404)
            ->push($this->reply('{"caption":"أصبح"}'))]);

        $result = app(Proofreader::class)->proofread(['caption' => 'أصلح'], 'saudi');

        $this->assertSame(['typo/model', 'main/model'], $this->models());
        $this->assertSame('أصبح', $result['content']['caption']);
    }

    public function test_a_real_outage_is_not_retried_with_another_model(): void
    {
        config(['ai.proofread.model' => 'fast/proofreader']);
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['code' => 402]], 402)]);

        $this->expectException(\App\Services\AI\ProviderException::class);

        try {
            app(Proofreader::class)->proofread(['caption' => 'نص'], 'saudi');
        } finally {
            $this->assertSame(['fast/proofreader'], $this->models());
        }
    }
}
