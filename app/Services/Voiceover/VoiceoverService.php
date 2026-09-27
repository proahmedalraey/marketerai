<?php

namespace App\Services\Voiceover;

use App\Enums\JobStatus;
use App\Jobs\GenerateVoiceoverJob;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\SpeechRequest;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * التعليق الصوتي: نص التاجر بصوت مذيع ولهجة وأسلوب إلقاء.
 *
 * يمر بالمسار الملزم كاملاً: مهمة في الطابور (لا نموذج داخل طلب HTTP)، وAiManager وحده
 * يكلّم المزود، والنقاط تُحجز بتقدير من طول النص ثم تُسوّى بالمدة الفعلية.
 *
 * بوابة الصدق: النموذج هنا لا يكتب — ينطق نص التاجر حرفياً. ما يمر بالذكاء قبل النطق
 * (تحسين للإلقاء، تشكيل) يحرسه VoiceScriptTools فلا يغيّر كلمة واحدة، فلا مدخل لادعاء جديد.
 *
 * النتيجة MediaAsset بنوع audio (الجدول صُمّم لها)، وتُحذف بعد مدة الاحتفاظ ما لم تُحفظ.
 */
class VoiceoverService
{
    public const OPERATION = 'voice.speech';

    public function __construct(
        protected AiManager $ai,
        protected CreditService $credits,
        protected VoiceCatalog $catalog,
    ) {}

    /**
     * @param  array{text: string, voice: string, style: string, custom_style?: ?string, language: string, dialect?: ?string, variant?: ?string, accent?: ?string, tier: string, content_item_id?: ?int}  $input
     */
    public function dispatch(Brand $brand, array $input, ?int $userId = null): GenerationJob
    {
        $text = trim($input['text']);
        $operation = $this->catalog->costOperation($input['tier']);
        $minutes = $this->catalog->estimateMinutes($text);

        return DB::transaction(function () use ($brand, $input, $text, $operation, $minutes, $userId) {
            $job = GenerationJob::create([
                'brand_id' => $brand->id,
                'user_id' => $userId,
                'type' => 'voiceover',
                'status' => JobStatus::Queued,
                'payload' => [
                    'text' => $text,
                    'voice' => $input['voice'],
                    'style' => $input['style'],
                    'custom_style' => $input['style'] === 'custom' ? trim((string) ($input['custom_style'] ?? '')) : null,
                    'language' => $input['language'],
                    'dialect' => $input['language'] === 'ar' ? ($input['dialect'] ?? 'auto') : null,
                    'variant' => $input['language'] === 'ar' ? ($input['variant'] ?? null) : null,
                    'accent' => $input['language'] === 'en' ? ($input['accent'] ?? null) : null,
                    'tier' => $input['tier'],
                    'content_item_id' => $input['content_item_id'] ?? null,
                    'estimated_minutes' => $minutes,
                    'credit_operation' => $operation,
                ],
            ]);

            $this->credits->hold($brand, $operation, $minutes, $job);

            // «media» مع الصور: التسجيل يستغرق ثوانٍ إلى دقيقة، فلا يؤخر كتابة المحتوى في «content»
            GenerateVoiceoverJob::dispatch($job->id)->onQueue('media');

            return $job->fresh();
        });
    }

    public function run(GenerationJob $job): void
    {
        $brand = $job->brand;
        $payload = (array) $job->payload;
        $voice = $this->catalog->voice($payload['voice'] ?? '') ?? $this->catalog->voice(config('voiceover.default_voice'));
        $tier = $this->catalog->tier($payload['tier'] ?? config('voiceover.default_tier'));

        $job->markProcessing();

        $response = $this->ai->generateSpeech(new SpeechRequest(
            transcript: (string) $payload['text'],
            voice: $voice['provider_voice'],
            direction: $this->catalog->direction($payload),
            model: $tier['model'] ?? null,
            operation: self::OPERATION,
        ), $job);

        $disk = config('ai.media_disk');
        $path = "brands/{$brand->id}/voiceovers/".Str::uuid().'.wav';

        Storage::disk($disk)->put($path, $response->audio);

        $asset = MediaAsset::create([
            'brand_id' => $brand->id,
            'generation_job_id' => $job->id,
            'kind' => 'audio',
            'disk' => $disk,
            'path' => $path,
            'mime' => $response->mime,
            'bytes' => strlen($response->audio),
            'prompt' => $payload['text'],
            'meta' => [
                'voice' => $payload['voice'],
                'style' => $payload['style'],
                'custom_style' => $payload['custom_style'] ?? null,
                'language' => $payload['language'],
                'dialect' => $payload['dialect'] ?? null,
                'variant' => $payload['variant'] ?? null,
                'accent' => $payload['accent'] ?? null,
                'tier' => $payload['tier'],
                'duration' => $response->durationSeconds,
                'model' => $response->model,
                // مصدر النص في الخطة: في meta لا في content_item_id، فالأخير تعرضه صفحة المحتوى صوراً
                'source_content_item_id' => $payload['content_item_id'] ?? null,
            ],
        ]);

        $held = (float) $job->credits_held;
        $consumed = $this->credits->cost($payload['credit_operation'], $this->catalog->billedMinutes($response->durationSeconds));

        $this->credits->settle($brand, $held, min($consumed, $held), $job, $payload['credit_operation']);

        $job->markCompleted([
            'media_ids' => [$asset->id],
            'duration' => $response->durationSeconds,
        ]);
    }

    /** سطر السجل للواجهة (السجل السابق). */
    public function historyItem(MediaAsset $asset): array
    {
        $meta = (array) $asset->meta;
        $voice = $this->catalog->voice($meta['voice'] ?? '');
        $tier = $this->catalog->tiers()[$meta['tier'] ?? ''] ?? null;
        $expiresAt = $asset->created_at->copy()->addDays((int) config('voiceover.retention_days', 30));
        $created = $asset->created_at->copy()->setTimezone(config('voiceover.display_timezone', 'Asia/Riyadh'));

        return [
            'id' => $asset->id,
            'url' => $asset->url(),
            'download' => 'voiceover-'.($voice['en'] ?? 'audio').'-'.$asset->id.'.wav',
            'format' => 'WAV',
            'voice' => $meta['voice'] ?? null,
            'voice_name' => $voice['name'] ?? 'مذيع',
            'voice_initial' => mb_substr($voice['name'] ?? 'م', 0, 1),
            'tier' => $tier['short'] ?? null,
            'style' => $this->catalog->styleLabel($meta['style'] ?? ''),
            'language' => $this->catalog->languageLabel($meta),
            'text' => (string) $asset->prompt,
            'duration' => (float) ($meta['duration'] ?? 0),
            'pinned' => (bool) $asset->is_pinned,
            'created' => $created->day.' '.VoiceScript::month($created->month).'، '
                .$created->format('h:i').' '.($created->format('A') === 'AM' ? 'ص' : 'م'),
            'expires' => $asset->is_pinned ? null : $this->remaining($expiresAt),
        ];
    }

    /** «29 يوم و 23 ساعة» حتى الحذف التلقائي. */
    protected function remaining(\Carbon\CarbonInterface $at): string
    {
        $hours = max(0, (int) floor(now()->diffInMinutes($at, false) / 60));

        if ($hours < 1) {
            return 'أقل من ساعة';
        }

        $days = intdiv($hours, 24);
        $rest = $hours % 24;

        return trim(($days ? "{$days} يوم" : '').($days && $rest ? ' و ' : '').($rest ? "{$rest} ساعة" : ''));
    }
}
