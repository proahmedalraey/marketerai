<?php

namespace Tests\Feature;

use App\Services\AI\AiManager;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.text_provider' => 'gemini',
            'ai.image_provider' => 'gemini',
            'ai.providers.gemini.api_key' => 'AIza-test-key',
            'ai.retry.times' => 0,
        ]);
    }

    public function test_text_generation_parses_json_and_skips_thoughts(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [
                        ['text' => 'تفكير داخلي', 'thought' => true],
                        ['text' => '{"caption":"قهوة مختصة"}'],
                    ]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 8, 'thoughtsTokenCount' => 20],
                'modelVersion' => 'gemini-2.5-flash',
            ]),
        ]);

        $response = app(AiManager::class)->generateText(new TextRequest(
            system: 'اكتب منشوراً', prompt: 'قهوة', schema: ['caption' => 'string'],
        ));

        $this->assertSame('قهوة مختصة', $response->data['caption']);
        $this->assertSame(28, $response->tokensOut);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/models/gemini-3.6-flash:generateContent')
            && $request->hasHeader('x-goog-api-key', 'AIza-test-key')
            && $request['generationConfig']['responseMimeType'] === 'application/json');
    }

    public function test_image_generation_returns_one_image_per_call(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [
                        ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNGDATA')]],
                    ]],
                ]],
            ]),
        ]);

        $response = app(AiManager::class)->generateImage(new ImageRequest(
            prompt: 'كوب قهوة', aspectRatio: '4:5', count: 2,
        ));

        $this->assertSame(2, $response->count());
        $this->assertSame('PNGDATA', $response->images[0]->contents);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['generationConfig']['imageConfig']['aspectRatio'] === '4:5'
            && ! isset($request['generationConfig']['imageConfig']['imageSize']));
    }

    public function test_blocked_prompt_raises_provider_error(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']]),
        ]);

        $this->expectException(ProviderException::class);

        app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p'));
    }

    // ================================================================
    //  التفكير يُحسب من سقف المخرجات (سجل 2026-09-22: كاروسيلان استهلكا 8177 من 8192
    //  تفكيراً فانقطع JSON، وخرجا «مخرجاً غير صالح» بلا سبب ظاهر)
    // ================================================================

    protected function okReply(string $text = '{"caption":"قهوة"}'): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']]];
    }

    public function test_thinking_is_kept_low_and_the_output_cap_has_room(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->okReply())]);

        app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p', schema: ['caption' => 'string'], maxTokens: 2048));

        Http::assertSent(fn ($request) => $request['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'low']
            && $request['generationConfig']['maxOutputTokens'] >= 16384);
    }

    public function test_older_models_get_a_token_budget_instead_of_a_level(): void
    {
        config(['ai.providers.gemini.model' => 'gemini-2.5-flash']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->okReply())]);

        app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p'));

        Http::assertSent(fn ($request) => $request['generationConfig']['thinkingConfig'] === ['thinkingBudget' => 1024]);
    }

    public function test_a_model_that_rejects_the_thinking_setting_is_asked_again_without_it(): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['error' => ['code' => 400, 'message' => 'Unknown name "thinkingLevel" at generation_config.thinking_config']], 400)
            ->push($this->okReply());

        $response = app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p', schema: ['caption' => 'string']));

        $this->assertSame('قهوة', $response->data['caption']);
        Http::assertSentCount(2);
    }

    public function test_a_reply_cut_off_at_the_token_cap_is_named_not_passed_as_empty(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"slides":[{"role":"hook","text":"قه']]], 'finishReason' => 'MAX_TOKENS']],
            'usageMetadata' => ['thoughtsTokenCount' => 8000, 'candidatesTokenCount' => 177],
        ])]);

        try {
            app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p', schema: ['slides' => 'array']));
            $this->fail('كان يجب أن يُرفض الرد المقطوع');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('MAX_TOKENS', $e->getMessage());
            $this->assertSame('انقطع رد الذكاء الاصطناعي قبل أن يكتمل. أُرجعت نقاطك — جرّب مجدداً.', $e->userMessage());
        }
    }

    public function test_thinking_can_be_turned_off(): void
    {
        config(['ai.providers.gemini.thinking' => 'off']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->okReply())]);

        app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p'));

        Http::assertSent(fn ($request) => ! isset($request['generationConfig']['thinkingConfig']));
    }
}
