<?php

namespace App\Services\Media;

use App\Services\AI\ModelCatalog;
use App\Services\Settings\AiSettings;

/**
 * نماذج استوديو الصور: ما يظهر في الواجهة، وما يقبله كل نموذج فعلاً.
 *
 * اختيار النموذج فعّال فقط حين image_provider = openrouter (نموذج واحد لكل طلب).
 * بغيره يُتجاهل ويعمل المزود المُعدّ كما هو. قدرات كل نموذج تُقرأ حياً من
 * OpenRouter (ModelCatalog، مخزَّنة ساعة)؛ تعذّر الجلب = بلا قيود معروفة.
 */
class StudioModels
{
    /** مستوى الواجهة → قيمة quality عند النماذج التي تقبلها (OpenAI/Grok) */
    public const QUALITY_MAP = [
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high',
        'very_high' => 'xhigh',
        'max' => 'max',
    ];

    public function __construct(protected ModelCatalog $catalog, protected AiSettings $settings) {}

    /** @return array<string, array{label: string, hint: string, openrouter: string}> */
    public function all(): array
    {
        return (array) config('ai.studio_models', []);
    }

    /**
     * مزود الصور الفعلي. إعدادات /settings/ai تعلو على .env لكنها لا تُطبَّق على
     * config إلا عبر AiSettings::apply — والطابور يطبّقها دائماً بينما طلب الويب لا،
     * فبدونها ترى الواجهة (fake من .env) غير ما سينفّذه العامل (openrouter).
     */
    public function provider(): string
    {
        $this->settings->apply();

        return (string) config('ai.image_provider');
    }

    public function routable(): bool
    {
        return $this->provider() === 'openrouter';
    }

    /** معرّف نموذج OpenRouter لمفتاح الواجهة، أو null إن كان التوجيه معطّلاً أو المفتاح مجهولاً. */
    public function modelId(?string $key): ?string
    {
        if (! $key || ! $this->routable()) {
            return null;
        }

        return $this->all()[$key]['openrouter'] ?? null;
    }

    /** تسمية ودّية لنموذج أُنتجت به صورة (من meta.model)، أو المعرّف نفسه إن لم يكن من القائمة. */
    public function labelFor(?string $modelId): ?string
    {
        if (! $modelId) {
            return null;
        }

        foreach ($this->all() as $entry) {
            if (($entry['openrouter'] ?? null) === $modelId) {
                return $entry['label'];
            }
        }

        return $modelId;
    }

    /**
     * ما يقبله النموذج. null لأي محور = بلا قيد معروف (كل خياراته متاحة).
     *
     * @return array{resolutions: ?array<int, string>, qualities: ?array<int, string>, ratios: ?array<int, string>}
     */
    public function capabilities(string $key): array
    {
        $none = ['resolutions' => null, 'qualities' => null, 'ratios' => null];

        $id = $this->all()[$key]['openrouter'] ?? null;
        $params = $id ? $this->catalog->imageParameters($id) : null;

        if ($params === null || $params === []) {
            return $none;
        }

        $resolutions = isset($params['resolution'])
            ? collect($params['resolution']['values'] ?? [])
                ->map(fn ($v) => strtolower((string) $v))
                ->filter(fn ($v) => in_array($v, ['1k', '2k', '4k'], true))
                ->values()->all()
            // بلا معامل دقة: النموذج ينتج مقاسه الأصلي فنعرضه 1K فقط
            : ['1k'];

        $qualities = isset($params['quality'])
            ? array_keys(array_filter(
                self::QUALITY_MAP,
                fn ($value) => in_array($value, $params['quality']['values'] ?? [], true)
            ))
            // بلا معامل جودة: المستويات كلها بلا أثر، فيُعرض مستوى واحد بدل تحصيل ثمن بلا مقابل
            : ['medium'];

        $ratios = isset($params['aspect_ratio'])
            ? collect($params['aspect_ratio']['values'] ?? [])->reject(fn ($v) => $v === 'auto')->values()->all()
            : null;

        return [
            'resolutions' => $resolutions === [] ? ['1k'] : $resolutions,
            'qualities' => $qualities === [] ? ['medium'] : $qualities,
            'ratios' => $ratios,
        ];
    }

    /** قدرات كل النماذج للواجهة. تُعطَّل القيود حين لا توجيه. */
    public function allCapabilities(): array
    {
        return collect($this->all())
            ->keys()
            ->mapWithKeys(fn ($key) => [$key => $this->routable()
                ? $this->capabilities($key)
                : ['resolutions' => null, 'qualities' => null, 'ratios' => null]])
            ->all();
    }

    /**
     * رسالة إن كانت تركيبة الدقة×الجودة غير مدعومة من النموذج المختار، وإلا null.
     * نرفض قبل الحجز: لا يُخصم ثمن 4K من نموذج ينتج 1K.
     */
    public function unsupported(?string $key, string $qualityKey): ?string
    {
        if (! $key || ! $this->routable() || ! isset($this->all()[$key])) {
            return null;
        }

        [$resolution, $level] = array_pad(explode('_', $qualityKey, 2), 2, null);
        $caps = $this->capabilities($key);
        $label = $this->all()[$key]['label'];

        if ($caps['resolutions'] !== null && ! in_array($resolution, $caps['resolutions'], true)) {
            return "النموذج «{$label}» لا يدعم دقة ".strtoupper((string) $resolution).'.';
        }

        if ($caps['qualities'] !== null && ! in_array($level, $caps['qualities'], true)) {
            return "النموذج «{$label}» لا يدعم مستوى الجودة المختار.";
        }

        return null;
    }
}
