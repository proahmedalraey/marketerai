<?php

namespace Tests\Feature;

use App\Services\AI\AiManager;
use App\Services\AI\Drivers\GeminiSpeechProvider;
use App\Services\AI\DTO\SpeechRequest;
use App\Services\AI\ProviderException;
use App\Services\AI\Support\Wav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiSpeechProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.speech_provider' => 'gemini',
            'ai.providers.gemini.api_key' => 'AIza-test-key',
            'ai.retry.times' => 0,
        ]);
    }

    protected function audioResponse(string $bytes, string $mime): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bytes)]]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 250],
            'modelVersion' => 'gemini-3.8-flash-tts',
        ];
    }

    public function test_raw_pcm_is_wrapped_in_a_playable_wav_with_its_duration(): void
    {
        // ثانيتان من PCM بمعدل 24000 وعينة 16 بت
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->audioResponse(str_repeat("\0", 96000), 'audio/L16;codec=pcm;rate=24000'))]);

        $response = app(AiManager::class)->generateSpeech(new SpeechRequest(
            transcript: 'هلا والله',
            voice: 'Kore',
            direction: 'in Najdi Saudi Arabic (Riyadh accent), warm and friendly',
            model: 'gemini-3.8-flash-lite-tts',
        ));

        $this->assertTrue(Wav::isWav($response->audio));
        $this->assertEquals(2.0, $response->durationSeconds);
        $this->assertSame(250, $response->tokensOut);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_contains($request->url(), '/models/gemini-3.8-flash-lite-tts:generateContent')
                && $body['generationConfig']['responseModalities'] === ['AUDIO']
                && $body['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Kore'
                // جملة توجيه واحدة ثم النص: لا عناوين يقرؤها النموذج بصوت عالٍ
                && $body['contents'][0]['parts'][0]['text'] === "Read aloud in Najdi Saudi Arabic (Riyadh accent), warm and friendly:\nهلا والله";
        });
    }

    public function test_prompt_is_one_sentence_without_headings_or_inner_colons(): void
    {
        $prompt = GeminiSpeechProvider::prompt(new SpeechRequest(
            transcript: "سطر أول\nسطر ثانٍ",
            voice: 'Kore',
            direction: "in Arabic,\n  following notes: slow.",
        ));

        $this->assertSame("Read aloud in Arabic, following notes, slow:\nسطر أول\nسطر ثانٍ", $prompt);
        $this->assertStringNotContainsString('TRANSCRIPT', $prompt);
        $this->assertStringNotContainsString('NOTES', $prompt);

        $this->assertSame("Read aloud naturally:\nنص", GeminiSpeechProvider::prompt(new SpeechRequest(transcript: 'نص', voice: 'Kore')));
    }

    public function test_safety_block_is_final_while_missing_audio_is_retried_once(): void
    {
        config(['ai.retry.times' => 1, 'ai.retry.sleep_ms' => 0]);

        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['candidates' => [['finishReason' => 'OTHER', 'content' => ['parts' => [['text' => 'نص بدل الصوت']]]]]])
            ->push($this->audioResponse(Wav::fromPcm(str_repeat("\0", 48000)), 'audio/wav'))
            ->push(['candidates' => [['finishReason' => 'SAFETY']]]);

        // الأول: رد بلا صوت ← إعادة تنجح
        $ok = app(AiManager::class)->generateSpeech(new SpeechRequest(transcript: 'نص', voice: 'Kore'));
        $this->assertEquals(1.0, $ok->durationSeconds);

        // الثاني: حجب ← لا إعادة، ورسالة تطلب تعديل النص أو الأسلوب
        try {
            app(AiManager::class)->generateSpeech(new SpeechRequest(transcript: 'نص', voice: 'Kore'));
            $this->fail('كان يجب أن يُرفض');
        } catch (ProviderException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('عدّل النص أو أسلوب الإلقاء', ProviderException::messageFor($e));
        }

        Http::assertSentCount(3);
    }

    public function test_wav_from_the_provider_is_kept_as_is_and_default_model_is_the_speech_model(): void
    {
        $wav = Wav::fromPcm(str_repeat("\0", 48000));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->audioResponse($wav, 'audio/wav'))]);

        $response = app(AiManager::class)->generateSpeech(new SpeechRequest(transcript: 'نص', voice: 'Puck'));

        $this->assertSame($wav, $response->audio);
        $this->assertEquals(1.0, $response->durationSeconds);

        // model في إعداد gemini هو نموذج النص؛ النطق لا يرسل إليه
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/models/gemini-3.8-flash-tts:generateContent'));
    }

    public function test_missing_audio_is_a_provider_error_with_a_merchant_message(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'no']]], 'finishReason' => 'OTHER']]])]);

        try {
            app(AiManager::class)->generateSpeech(new SpeechRequest(transcript: 'نص', voice: 'Kore'));
            $this->fail('كان يجب أن يفشل');
        } catch (ProviderException $e) {
            $this->assertSame('لم يُعد مزود الصوت تسجيلاً. أُرجعت نقاطك — جرّب مجدداً.', ProviderException::messageFor($e));
        }
    }

    public function test_wav_duration_reads_the_data_chunk(): void
    {
        $this->assertEquals(1.5, Wav::duration(Wav::fromPcm(str_repeat("\0", 72000))));
        $this->assertEquals(0.0, Wav::duration('not audio'));
    }
}
