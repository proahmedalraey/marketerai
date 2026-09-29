<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Services\AI\AiManager;
use App\Services\Credits\DailyCapReachedException;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Voiceover\VoiceCatalog;
use App\Services\Voiceover\VoiceoverService;
use App\Services\Voiceover\VoiceSamples;
use App\Services\Voiceover\VoiceScript;
use App\Services\Voiceover\VoiceScriptTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * التعليق الصوتي (Beta) في «صناعة المحتوى».
 *
 * متحكم رفيع: كل توليد مهمة في الطابور تُستطلع من /api/jobs/{uuid}، والواجهة
 * (resources/js/voiceover.js) تتعامل معه بـ fetch فلا تُفقد الإعدادات بإعادة تحميل.
 */
class VoiceoverController extends Controller
{
    public const MAX_BATCH = 10;

    public const HISTORY_LIMIT = 50;

    public function __construct(protected VoiceCatalog $catalog) {}

    public function index(AiManager $ai, VoiceoverService $service)
    {
        $planItems = ContentItem::inPlan()
            ->where('status', 'ready')
            ->with('product')
            ->latest('planned_for')
            ->latest('id')
            ->take(60)
            ->get()
            ->map(fn (ContentItem $item) => VoiceScript::card($item))
            ->filter(fn (array $card) => $card['text'] !== '')
            ->values();

        return view('content.voiceover', [
            'studio' => [
                'voices' => $this->catalog->voiceCards(),
                'styles' => collect($this->catalog->styles())->map(fn ($s) => ['label' => $s['label'], 'icon' => $s['icon'], 'hint' => $s['hint'] ?? ''])->all(),
                'dialects' => collect($this->catalog->dialects())->map(fn ($d) => [
                    'label' => $d['label'],
                    'variants' => collect($d['variants'] ?? [])->map(fn ($v) => $v['label'])->all(),
                    'defaultVariant' => $d['default_variant'] ?? null,
                ])->all(),
                'accents' => collect($this->catalog->accents())->map(fn ($a) => ['label' => $a['label'], 'en' => $a['en']])->all(),
                'tiers' => collect($this->catalog->tiers())->map(fn ($t, $key) => [
                    'label' => $t['label'], 'short' => $t['short'], 'hint' => $t['hint'], 'icon' => $t['icon'],
                ])->all(),
                'minuteCosts' => $this->catalog->minuteCosts(),
                'wordsPerMinute' => (int) config('voiceover.words_per_minute', 120),
                'defaults' => [
                    'voice' => config('voiceover.default_voice'),
                    'style' => config('voiceover.default_style'),
                    'dialect' => config('voiceover.default_dialect'),
                    'accent' => config('voiceover.default_accent'),
                    'tier' => config('voiceover.default_tier'),
                ],
                'maxChars' => (int) config('voiceover.max_chars', 2000),
                'maxBatch' => self::MAX_BATCH,
                'retentionDays' => (int) config('voiceover.retention_days', 30),
                'planItems' => $planItems,
                'history' => $this->history($service),
                'running' => $this->running(),
                'speechReady' => $ai->ready((string) config('ai.speech_provider')),
                'balance' => (float) ($this->brand()->credit_balance ?? 0),
                'storageKey' => 'voiceover.batch.'.$this->brand()->id,
                'urls' => [
                    'store' => route('voiceover.store'),
                    'history' => route('voiceover.history'),
                    'tool' => route('voiceover.tool', ['tool' => '__tool__']),
                    'sample' => route('voiceover.sample', ['voice' => '__voice__']),
                    'pin' => route('voiceover.pin', ['mediaAsset' => '__id__']),
                    'destroy' => route('voiceover.destroy', ['mediaAsset' => '__id__']),
                    'job' => url('/api/jobs/__uuid__'),
                ],
            ],
        ]);
    }

    /** السجل والمهام الجارية: زر «تحديث»، وبعد اكتمال كل توليد. */
    public function historyJson(VoiceoverService $service): JsonResponse
    {
        return response()->json([
            'items' => $this->history($service),
            'running' => $this->running(),
            'balance' => (float) $this->brand()->fresh()->credit_balance,
        ]);
    }

    public function store(Request $request, AiManager $ai, VoiceoverService $service): JsonResponse
    {
        $data = $request->validate($this->rules(), [
            'items.required' => 'لا شيء للتوليد.',
            'items.max' => 'الدفعة تتسع لـ'.self::MAX_BATCH.' تعليقات كحد أقصى.',
            'items.*.text.required' => 'اكتب النص الذي سيُقرأ أولاً.',
            'items.*.custom_style.required_if' => 'اكتب توجيهات الأسلوب المخصص أو اختر نمطاً جاهزاً.',
        ]);

        // قبل الحجز: بلا مفتاح لا تسجيل، ولا نحجز نقاطاً لمهمة ستفشل حتماً
        if (! $ai->ready((string) config('ai.speech_provider'))) {
            return response()->json(['message' => 'التعليق الصوتي غير مفعّل بعد: يحتاج مفتاح Gemini في إعدادات الذكاء الاصطناعي.'], 422);
        }

        $jobs = [];

        foreach ($data['items'] as $item) {
            try {
                $job = $service->dispatch($this->brand(), $item, $request->user()->id);
            } catch (InsufficientCreditsException|DailyCapReachedException $e) {
                // ما أُطلق قبله يبقى يعمل؛ الرسالة تقول كم بقي دون توليد
                return response()->json([
                    'message' => $e->getMessage().($jobs ? ' — بدأ توليد '.count($jobs).' وتوقف الباقي.' : ''),
                    'jobs' => $jobs,
                ], $jobs ? 207 : 422);
            }

            $jobs[] = $this->runningItem($job);
        }

        return response()->json(['jobs' => $jobs], 202);
    }

    public function tool(Request $request, string $tool, VoiceScriptTools $tools): JsonResponse
    {
        abort_unless(in_array($tool, VoiceScriptTools::TOOLS, true), 404);

        $data = $request->validate([
            'text' => ['required', 'string', 'min:3', $this->textLength()],
            'style' => ['nullable', Rule::in(array_keys($this->catalog->styles()))],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
        ], [
            'text.required' => 'اكتب النص أولاً.',
            'text.min' => 'النص قصير جداً.',
        ]);

        try {
            $job = $tools->dispatch($this->brand(), $tool, $data, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['uuid' => $job->uuid, 'status_url' => route('api.jobs.show', $job)], 202);
    }

    /** عينة «استمع»: الرابط إن جهزت (200)، وإلا تُجهَّز في الطابور (202) والواجهة تعيد السؤال. */
    public function sample(Request $request, string $voice, AiManager $ai, VoiceSamples $samples): JsonResponse
    {
        abort_unless($this->catalog->voice($voice), 404);

        $language = $request->query('language') === 'en' ? 'en' : 'ar';

        if ($url = $samples->url($voice, $language)) {
            return response()->json(['url' => $url]);
        }

        if (! $ai->ready((string) config('ai.speech_provider'))) {
            return response()->json(['message' => 'العينات تحتاج تفعيل التعليق الصوتي (مفتاح Gemini).'], 422);
        }

        // فشلت آخر محاولة (حصة المزود مثلاً): السبب الآن بدل انتظار 45 ثانية ثم «تتأخر»
        if ($reason = $samples->pullFailure($voice, $language)) {
            return response()->json(['message' => $reason], 422);
        }

        $url = $samples->request($voice, $language);

        return $url ? response()->json(['url' => $url]) : response()->json(['pending' => true], 202);
    }

    /** «حفظ»: المحفوظ لا يُحذف بعد مدة الاحتفاظ. */
    public function pin(MediaAsset $mediaAsset, VoiceoverService $service): JsonResponse
    {
        $this->authorizeAudio($mediaAsset);

        $mediaAsset->update(['is_pinned' => ! $mediaAsset->is_pinned]);

        return response()->json($service->historyItem($mediaAsset));
    }

    public function destroy(MediaAsset $mediaAsset): JsonResponse
    {
        $this->authorizeAudio($mediaAsset);

        $mediaAsset->deleteFiles();
        $mediaAsset->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * ربط المسار يسبق وسيط brand.ready، فنطاق BelongsToBrand لا يعمل عنده بعد
     * (CurrentBrand فارغ في طلب جديد) — التحقق من المالك هنا صريح لا ضمني.
     */
    protected function authorizeAudio(MediaAsset $mediaAsset): void
    {
        abort_unless($mediaAsset->kind === 'audio' && $mediaAsset->brand_id === $this->brand()->id, 404);
    }

    protected function rules(): array
    {
        $dialects = $this->catalog->dialects();
        $variants = collect($dialects)->flatMap(fn ($d) => array_keys($d['variants'] ?? []))->unique()->values()->all();

        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'items.*.text' => ['required', 'string', $this->textLength()],
            'items.*.voice' => ['required', Rule::in(array_keys($this->catalog->voices()))],
            'items.*.style' => ['required', Rule::in(array_keys($this->catalog->styles()))],
            'items.*.custom_style' => ['nullable', 'required_if:items.*.style,custom', 'string', 'max:500'],
            'items.*.language' => ['required', Rule::in(['ar', 'en'])],
            'items.*.dialect' => ['nullable', Rule::in(array_keys($dialects))],
            'items.*.variant' => ['nullable', Rule::in($variants)],
            'items.*.accent' => ['nullable', Rule::in(array_keys($this->catalog->accents()))],
            'items.*.tier' => ['required', Rule::in(array_keys($this->catalog->tiers()))],
            'items.*.content_item_id' => [
                'nullable', 'integer',
                Rule::exists('content_items', 'id')->where('brand_id', $this->brand()->id),
            ],
        ];
    }

    /** حد النص بلا وسوم الأداء: [short pause] ليست كلاماً يُحسب على التاجر. */
    protected function textLength(): \Closure
    {
        $max = (int) config('voiceover.max_chars', 2000);

        return function (string $attribute, mixed $value, \Closure $fail) use ($max) {
            $spoken = preg_replace('/\[[^\]\n]{1,40}\]/u', '', (string) $value);

            if (mb_strlen(trim($spoken)) > $max) {
                $fail("النص أطول من {$max} حرف. قسّمه على أكثر من تعليق.");
            }
        };
    }

    protected function history(VoiceoverService $service): array
    {
        return MediaAsset::where('kind', 'audio')
            ->latest()
            ->take(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (MediaAsset $asset) => $service->historyItem($asset))
            ->all();
    }

    protected function running(): array
    {
        return GenerationJob::where('type', 'voiceover')
            ->whereIn('status', ['queued', 'processing'])
            ->latest()
            ->take(self::MAX_BATCH)
            ->get()
            ->map(fn (GenerationJob $job) => $this->runningItem($job))
            ->all();
    }

    protected function runningItem(GenerationJob $job): array
    {
        $payload = (array) $job->payload;
        $voice = $this->catalog->voice($payload['voice'] ?? '');

        return [
            'uuid' => $job->uuid,
            'voice_name' => $voice['name'] ?? 'مذيع',
            'voice_initial' => mb_substr($voice['name'] ?? 'م', 0, 1),
            'style' => $this->catalog->styleLabel($payload['style'] ?? ''),
            'tier' => $this->catalog->tiers()[$payload['tier'] ?? '']['short'] ?? null,
            'text' => Str::limit((string) ($payload['text'] ?? ''), 160),
        ];
    }
}
