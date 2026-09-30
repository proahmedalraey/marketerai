<?php

namespace App\Http\Controllers;

use App\Services\AI\AiManager;
use App\Services\AI\DTO\SpeechRequest;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\ModelCatalog;
use App\Services\AI\ProviderException;
use App\Services\Settings\AiSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * إعدادات المنصة: ربط مزودي الذكاء الاصطناعي بمفاتيحهم ونماذجهم.
 */
class PlatformSettingsController extends Controller
{
    /** المزودون المتاحون لكل نوع، كما يدعمهم AiManager */
    public const TEXT_PROVIDERS = [
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI (GPT)',
        'gemini' => 'Google Gemini',
        'openrouter' => 'OpenRouter (مئات النماذج بمفتاح واحد)',
        'fake' => 'وضع التجربة — بلا مفاتيح ولا تكلفة',
    ];

    public const IMAGE_PROVIDERS = [
        'openai' => 'OpenAI (GPT Image)',
        'gemini' => 'Google Gemini (Nano Banana)',
        'openrouter' => 'OpenRouter (عشرات نماذج الصور)',
        'fake' => 'وضع التجربة — بلا مفاتيح ولا تكلفة',
    ];

    /** النطق للتعليق الصوتي: Gemini TTS وحده بين المزودين المدعومين */
    public const SPEECH_PROVIDERS = [
        'gemini' => 'Google Gemini (TTS)',
        'fake' => 'وضع التجربة — نغمة بدل الصوت، بلا تكلفة',
    ];

    /** أسماء العرض: ucfirst يعطي «Openrouter» */
    public const LABELS = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'gemini' => 'Gemini',
        'openrouter' => 'OpenRouter',
    ];

    public function __construct(protected AiSettings $settings) {}

    public function edit(Request $request, ModelCatalog $catalog): View
    {
        $this->shareBrand($request);

        $fields = [];

        foreach (array_keys(AiSettings::FIELDS) as $key) {
            $fields[$key] = [
                'value' => $this->settings->value($key),
                'mask' => AiSettings::FIELDS[$key]['secret'] ? $this->settings->mask($key) : null,
                'source' => $this->settings->source($key),
            ];
        }

        return view('settings.ai', [
            'fields' => $fields,
            'catalogs' => collect(array_keys(self::LABELS))
                ->mapWithKeys(fn ($p) => [$p => $catalog->for($p)])
                ->all(),
            'textProviders' => self::TEXT_PROVIDERS,
            'imageProviders' => self::IMAGE_PROVIDERS,
            'speechProviders' => self::SPEECH_PROVIDERS,
            'voiceTiers' => config('voiceover.tiers', []),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text_provider' => ['required', Rule::in(array_keys(self::TEXT_PROVIDERS))],
            'image_provider' => ['required', Rule::in(array_keys(self::IMAGE_PROVIDERS))],
            // sometimes: نموذج حُفظ قبل قسم التعليق الصوتي (أو اختبار قديم) لا يُسقط الحفظ ولا يمسح اختياره
            'speech_provider' => ['sometimes', 'required', Rule::in(array_keys(self::SPEECH_PROVIDERS))],
            'voiceover_standard_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'voiceover_hd_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],

            'anthropic_api_key' => ['nullable', 'string', 'max:500'],
            'anthropic_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'anthropic_base_url' => ['nullable', 'url:https,http', 'max:255'],

            'openai_api_key' => ['nullable', 'string', 'max:500'],
            'openai_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'openai_image_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'openai_base_url' => ['nullable', 'url:https,http', 'max:255'],

            'gemini_api_key' => ['nullable', 'string', 'max:500'],
            'gemini_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'gemini_image_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'gemini_base_url' => ['nullable', 'url:https,http', 'max:255'],

            'openrouter_api_key' => ['nullable', 'string', 'max:500'],
            'openrouter_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:~\/-]+$/'],
            'openrouter_image_model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:~\/-]+$/'],
            'openrouter_base_url' => ['nullable', 'url:https,http', 'max:255'],

            'clear' => ['array'],
            'clear.*' => [Rule::in(['anthropic.api_key', 'openai.api_key', 'gemini.api_key', 'openrouter.api_key'])],
        ], [
            '*.regex' => 'اسم النموذج يقبل حروفاً إنجليزية وأرقاماً و . - _ : / ~ فقط.',
        ], [
            'text_provider' => 'مزود النصوص',
            'image_provider' => 'مزود الصور',
            'speech_provider' => 'مزود الصوت',
            'voiceover_standard_model' => 'نموذج Marketerai 2.3',
            'voiceover_hd_model' => 'نموذج Marketerai 3.1',
            'anthropic_api_key' => 'مفتاح Anthropic',
            'anthropic_model' => 'نموذج Anthropic',
            'anthropic_base_url' => 'رابط Anthropic',
            'openai_api_key' => 'مفتاح OpenAI',
            'openai_model' => 'نموذج OpenAI للنصوص',
            'openai_image_model' => 'نموذج OpenAI للصور',
            'openai_base_url' => 'رابط OpenAI',
            'gemini_api_key' => 'مفتاح Gemini',
            'gemini_model' => 'نموذج Gemini للنصوص',
            'gemini_image_model' => 'نموذج Gemini للصور',
            'gemini_base_url' => 'رابط Gemini',
            'openrouter_api_key' => 'مفتاح OpenRouter',
            'openrouter_model' => 'نموذج OpenRouter للنصوص',
            'openrouter_image_model' => 'نموذج OpenRouter للصور',
            'openrouter_base_url' => 'رابط OpenRouter',
        ]);

        $clear = $data['clear'] ?? [];

        $this->settings->save([
            'ai.text_provider' => $data['text_provider'],
            'ai.image_provider' => $data['image_provider'],
            'anthropic.api_key' => $data['anthropic_api_key'] ?? null,
            'anthropic.model' => $data['anthropic_model'] ?? null,
            'anthropic.base_url' => $data['anthropic_base_url'] ?? null,
            'openai.api_key' => $data['openai_api_key'] ?? null,
            'openai.model' => $data['openai_model'] ?? null,
            'openai.image_model' => $data['openai_image_model'] ?? null,
            'openai.base_url' => $data['openai_base_url'] ?? null,
            'gemini.api_key' => $data['gemini_api_key'] ?? null,
            'gemini.model' => $data['gemini_model'] ?? null,
            'gemini.image_model' => $data['gemini_image_model'] ?? null,
            'gemini.base_url' => $data['gemini_base_url'] ?? null,
            'openrouter.api_key' => $data['openrouter_api_key'] ?? null,
            'openrouter.model' => $data['openrouter_model'] ?? null,
            'openrouter.image_model' => $data['openrouter_image_model'] ?? null,
            'openrouter.base_url' => $data['openrouter_base_url'] ?? null,
        ] + ($request->has('speech_provider') ? [
            'ai.speech_provider' => $data['speech_provider'],
            'voiceover.standard_model' => $data['voiceover_standard_model'] ?? null,
            'voiceover.hd_model' => $data['voiceover_hd_model'] ?? null,
        ] : []), $clear, $request->user()->id);

        $this->settings->apply();

        // مزود مختار بلا مفتاح سيُسقط كل توليد لاحق؛ الأفضل أن يعرف المدير الآن
        $missing = collect([$data['text_provider'], $data['image_provider'], $data['speech_provider'] ?? null])
            ->filter()
            ->unique()
            ->reject(fn ($p) => $p === 'fake' || filled(config("ai.providers.{$p}.api_key")));

        $status = $missing->isEmpty()
            ? 'تم حفظ إعدادات الذكاء الاصطناعي.'
            : 'تم الحفظ، لكن لا يوجد مفتاح API لـ '.$missing->map(fn ($p) => self::LABELS[$p] ?? $p)->implode(' و ').' — التوليد سيفشل حتى تضيفه.';

        return redirect()->route('settings.ai')->with('status', $status);
    }

    /**
     * اختبار اتصال حقيقي بالإعدادات المحفوظة: طلب صغير جداً لا يكلف شيئاً يُذكر.
     */
    public function test(Request $request, AiManager $ai): RedirectResponse
    {
        if ($request->input('kind') === 'speech') {
            return $this->testSpeech($request, $ai);
        }

        $provider = $request->validate([
            'provider' => ['required', Rule::in(array_keys(self::LABELS))],
        ])['provider'];

        $this->settings->apply();

        if (blank(config("ai.providers.{$provider}.api_key"))) {
            return back()->withErrors(['provider' => 'أضف مفتاح API واحفظه أولاً، ثم اختبر الاتصال.']);
        }

        try {
            $driver = $ai->text($provider);

            $response = $driver->generate(new TextRequest(
                system: 'You are a connectivity check. Reply with the single word OK.',
                prompt: 'ping',
                temperature: 0,
                maxTokens: 16,
                operation: 'settings.test',
            ));
        } catch (Throwable $e) {
            // كل رمز يشير لعلاج مختلف؛ 404 مثلاً يعني نموذجاً سُحب لا مفتاحاً خاطئاً
            $hint = match ($e instanceof ProviderException ? $e->statusCode : null) {
                404 => ' — النموذج «'.config("ai.providers.{$provider}.model").'» غير متاح لحسابك؛ اختر نموذجاً آخر من القائمة واحفظ.',
                401 => ' — المفتاح غير صحيح أو ملغى؛ الصق مفتاحاً جديداً.',
                402 => ' — رصيد الحساب غير كافٍ؛ اشحن رصيدك في لوحة المزود.',
                default => '',
            };

            return back()->withErrors([
                'provider' => 'فشل الاتصال بـ '.(self::LABELS[$provider] ?? $provider).$hint.' التفاصيل: '.mb_substr($e->getMessage(), 0, 300),
            ]);
        }

        return back()->with('status', sprintf(
            'الاتصال بـ %s يعمل — النموذج %s ردّ خلال %s ث.',
            self::LABELS[$provider] ?? $provider,
            $response->model,
            number_format($response->latencyMs / 1000, 1)
        ));
    }

    /**
     * اختبار التعليق الصوتي لمستوى جودة بعينه: تسجيل جملة قصيرة بنموذجه.
     * يكشف أيضاً نفاد الحصة: الحساب المجاني 10 طلبات نطق يومياً لكل نموذج.
     *
     * جملة لا كلمة: «مرحباً» وحدها أعادت رداً بلا صوت (finishReason: OTHER) مرتين متتاليتين
     * في تجربة التاجر 2026-09-30 — نماذج النطق تتعثر مع المدخل القصير جداً.
     */
    protected function testSpeech(Request $request, AiManager $ai): RedirectResponse
    {
        $this->settings->apply();

        $tier = $request->validate([
            'tier' => ['required', Rule::in(array_keys(config('voiceover.tiers', [])))],
        ])['tier'];

        $provider = (string) config('ai.speech_provider');
        $model = (string) config("voiceover.tiers.{$tier}.model");
        $label = config("voiceover.tiers.{$tier}.label");

        if (! $ai->ready($provider)) {
            return back()->withErrors(['speech' => 'أضف مفتاح Gemini واحفظه أولاً، ثم اختبر التعليق الصوتي.']);
        }

        try {
            $response = $ai->generateSpeech(new SpeechRequest(
                transcript: 'مرحباً بك، هذا اختبار سريع للتعليق الصوتي في منصتك.',
                voice: 'Kore',
                direction: 'in neutral white Saudi and Gulf Arabic, friendly and clear',
                model: $model,
                operation: 'settings.test',
            ), null, $provider);
        } catch (Throwable $e) {
            $status = $e instanceof ProviderException ? $e->statusCode : null;

            $hint = match (true) {
                $e instanceof ProviderException && $e->quotaExhausted => ' — انتهت الحصة اليومية لهذا النموذج. الحساب المجاني يسمح بـ10 طلبات نطق يومياً لكل نموذج: فعّل الفوترة في Google AI Studio، أو اختر لهذا المستوى نموذجاً آخر.',
                $status === 404 => " — النموذج «{$model}» غير متاح لحسابك؛ اختر نموذجاً آخر من القائمة واحفظ.",
                $status === 401, $status === 403 => ' — المفتاح غير صحيح أو ملغى؛ الصق مفتاحاً جديداً في قسم Gemini.',
                // رد 200 بلا صوت بعد الإعادة التلقائية: عثرة عند المزود لا خطأ في المفتاح
                $status === 200 && str_contains($e->getMessage(), 'لم يُعد Gemini صوتاً') => ' — أعاد النموذج رداً بلا صوت رغم الإعادة. عثرة عابرة عند Google غالباً: جرّب بعد دقيقة، وإن تكررت فاختر لهذا المستوى نموذجاً آخر.',
                default => '',
            };

            return back()->withErrors([
                'speech' => "فشل اختبار {$label} ({$model}){$hint} التفاصيل: ".mb_substr($e->getMessage(), 0, 200),
            ]);
        }

        return back()->with('status', sprintf(
            'التعليق الصوتي يعمل — %s بنموذج %s سجّل %s ث من الصوت خلال %s ث.',
            $label,
            $response->model,
            number_format($response->durationSeconds, 1),
            number_format($response->latencyMs / 1000, 1)
        ));
    }

    /**
     * الصفحة خارج brand.ready (المدير قد لا يملك علامة)، لكن الشريط الجانبي
     * يعرض رصيد العلامة إن وُجدت.
     */
    protected function shareBrand(Request $request): void
    {
        if ($brand = $request->user()->resolveBrand()) {
            view()->share('currentBrand', $brand);
        }
    }
}
