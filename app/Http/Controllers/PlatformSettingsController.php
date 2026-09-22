<?php

namespace App\Http\Controllers;

use App\Services\AI\AiManager;
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
        'fake' => 'وضع التجربة — بلا مفاتيح ولا تكلفة',
    ];

    public const IMAGE_PROVIDERS = [
        'openai' => 'OpenAI (GPT Image)',
        'gemini' => 'Google Gemini (Nano Banana)',
        'fake' => 'وضع التجربة — بلا مفاتيح ولا تكلفة',
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
            'catalogs' => collect(['anthropic', 'openai', 'gemini'])
                ->mapWithKeys(fn ($p) => [$p => $catalog->for($p)])
                ->all(),
            'textProviders' => self::TEXT_PROVIDERS,
            'imageProviders' => self::IMAGE_PROVIDERS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text_provider' => ['required', Rule::in(array_keys(self::TEXT_PROVIDERS))],
            'image_provider' => ['required', Rule::in(array_keys(self::IMAGE_PROVIDERS))],

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

            'clear' => ['array'],
            'clear.*' => [Rule::in(['anthropic.api_key', 'openai.api_key', 'gemini.api_key'])],
        ], [
            '*.regex' => 'اسم النموذج يقبل حروفاً إنجليزية وأرقاماً و . - _ : / فقط.',
        ], [
            'text_provider' => 'مزود النصوص',
            'image_provider' => 'مزود الصور',
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
        ], $clear, $request->user()->id);

        $this->settings->apply();

        // مزود مختار بلا مفتاح سيُسقط كل توليد لاحق؛ الأفضل أن يعرف المدير الآن
        $missing = collect([$data['text_provider'], $data['image_provider']])
            ->unique()
            ->reject(fn ($p) => $p === 'fake' || filled(config("ai.providers.{$p}.api_key")));

        $status = $missing->isEmpty()
            ? 'تم حفظ إعدادات الذكاء الاصطناعي.'
            : 'تم الحفظ، لكن لا يوجد مفتاح API لـ '.$missing->map(fn ($p) => ucfirst($p))->implode(' و ').' — التوليد سيفشل حتى تضيفه.';

        return redirect()->route('settings.ai')->with('status', $status);
    }

    /**
     * اختبار اتصال حقيقي بالإعدادات المحفوظة: طلب صغير جداً لا يكلف شيئاً يُذكر.
     */
    public function test(Request $request, AiManager $ai): RedirectResponse
    {
        $provider = $request->validate([
            'provider' => ['required', Rule::in(['anthropic', 'openai', 'gemini'])],
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
            // 404 يعني غالباً أن النموذج سُحب أو غير متاح لهذا الحساب، لا أن المفتاح خاطئ
            $hint = $e instanceof ProviderException && $e->statusCode === 404
                ? ' — النموذج «'.config("ai.providers.{$provider}.model").'» غير متاح لحسابك؛ اختر نموذجاً آخر من القائمة واحفظ.'
                : '';

            return back()->withErrors([
                'provider' => 'فشل الاتصال بـ '.ucfirst($provider).$hint.' التفاصيل: '.mb_substr($e->getMessage(), 0, 300),
            ]);
        }

        return back()->with('status', sprintf(
            'الاتصال بـ %s يعمل — النموذج %s ردّ خلال %s ث.',
            ucfirst($provider),
            $response->model,
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
