<?php

namespace Tests\Feature;

use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\Content\ContentSchema;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مزود Gemini يُلزَم بالمخطط عبر responseSchema، مع شبكة أمان إن رُفض.
 */
class GeminiSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.text_provider' => 'gemini',
            'ai.providers.gemini.api_key' => 'test-key',
            'ai.retry.sleep_ms' => 1,
        ]);
    }

    protected function ok(): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => '{"caption":"نص","hashtags":["قهوة"]}']]]]]];
    }

    public function test_the_schema_is_sent_in_gemini_form(): void
    {
        Http::fake(['*' => Http::response($this->ok())]);

        $schema = ContentSchema::for('carousel', 5);
        $schema['additionalProperties'] = false; // غير مدعوم في Gemini: يجب أن يُسقط

        app(AiManager::class)->generateText(new TextRequest('system', 'prompt', $schema));

        Http::assertSent(function (Request $request) use ($schema) {
            $sent = $request['generationConfig']['responseSchema'] ?? null;

            return $sent !== null
                && $sent['type'] === 'OBJECT'
                // ترتيب المخطط يُرسل صراحة، وإلا كتب Gemini الحقول أبجدياً
                && $sent['propertyOrdering'] === array_keys($schema['properties'])
                && ! isset($sent['additionalProperties'])
                && $sent['properties']['slides']['type'] === 'ARRAY'
                && $sent['properties']['slides']['items']['type'] === 'OBJECT'
                && $sent['properties']['slides']['items']['properties']['role']['enum'] === ['hook', 'promise', 'pull', 'harvest', 'ask']
                && $sent['properties']['slides']['minItems'] === 5
                && $request['generationConfig']['responseMimeType'] === 'application/json';
        });
    }

    public function test_free_text_requests_carry_no_schema(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'نص حر']]]]]])]);

        app(AiManager::class)->generateText(new TextRequest('system', 'prompt'));

        Http::assertSent(fn (Request $r) => ! isset($r['generationConfig']['responseSchema']));
    }

    public function test_a_rejected_schema_falls_back_to_an_unconstrained_request(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['code' => 400, 'message' => 'Invalid JSON payload received. Unknown name "responseSchema"']], 400)
            ->push($this->ok(), 200);

        $response = app(AiManager::class)->generateText(new TextRequest('system', 'prompt', ContentSchema::for('post')));

        $this->assertSame('نص', $response->data['caption']);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertArrayHasKey('responseSchema', $requests[0][0]['generationConfig']);
        $this->assertArrayNotHasKey('responseSchema', $requests[1][0]['generationConfig']);
    }

    public function test_an_unrelated_bad_request_is_not_resent(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 400, 'message' => 'API key not valid.']], 400)]);

        try {
            app(AiManager::class)->generateText(new TextRequest('system', 'prompt', ContentSchema::for('post')));
        } catch (\App\Services\AI\ProviderException) {
        }

        Http::assertSentCount(1);
    }
}
