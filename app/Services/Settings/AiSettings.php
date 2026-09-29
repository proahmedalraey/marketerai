<?php

namespace App\Services\Settings;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إعدادات الذكاء الاصطناعي المدارة من الواجهة.
 *
 * ما يُحفظ هنا يعلو على config/ai.php (أي على ملف البيئة)، وما لم يُحفظ
 * يبقى على قيمة البيئة. لذلك يعمل التثبيت الجديد بلا أي إعداد،
 * ويستطيع المدير تبديل المزود أو المفتاح دون لمس الخادم.
 */
class AiSettings
{
    protected const CACHE_KEY = 'platform_settings.ai';

    /**
     * كل حقل: مساره في config، وهل هو سري.
     */
    public const FIELDS = [
        'ai.text_provider' => ['config' => 'ai.text_provider', 'secret' => false],
        'ai.image_provider' => ['config' => 'ai.image_provider', 'secret' => false],
        'ai.speech_provider' => ['config' => 'ai.speech_provider', 'secret' => false],

        // التعليق الصوتي: نموذج كل مستوى جودة (Marketerai 2.3 / 3.1)
        'voiceover.standard_model' => ['config' => 'voiceover.tiers.standard.model', 'secret' => false],
        'voiceover.hd_model' => ['config' => 'voiceover.tiers.hd.model', 'secret' => false],

        'anthropic.api_key' => ['config' => 'ai.providers.anthropic.api_key', 'secret' => true],
        'anthropic.model' => ['config' => 'ai.providers.anthropic.model', 'secret' => false],
        'anthropic.base_url' => ['config' => 'ai.providers.anthropic.base_url', 'secret' => false],

        'openai.api_key' => ['config' => 'ai.providers.openai.api_key', 'secret' => true],
        'openai.model' => ['config' => 'ai.providers.openai.model', 'secret' => false],
        'openai.image_model' => ['config' => 'ai.providers.openai.image_model', 'secret' => false],
        'openai.base_url' => ['config' => 'ai.providers.openai.base_url', 'secret' => false],

        'gemini.api_key' => ['config' => 'ai.providers.gemini.api_key', 'secret' => true],
        'gemini.model' => ['config' => 'ai.providers.gemini.model', 'secret' => false],
        'gemini.image_model' => ['config' => 'ai.providers.gemini.image_model', 'secret' => false],
        'gemini.base_url' => ['config' => 'ai.providers.gemini.base_url', 'secret' => false],

        'openrouter.api_key' => ['config' => 'ai.providers.openrouter.api_key', 'secret' => true],
        'openrouter.model' => ['config' => 'ai.providers.openrouter.model', 'secret' => false],
        'openrouter.image_model' => ['config' => 'ai.providers.openrouter.image_model', 'secret' => false],
        'openrouter.base_url' => ['config' => 'ai.providers.openrouter.base_url', 'secret' => false],
    ];

    /** قيم البيئة الأصلية قبل أي تطبيق، للرجوع إليها حين يُحذف الإعداد */
    protected ?array $envDefaults = null;

    protected ?string $appliedVersion = null;

    /**
     * يطبّق القيم المحفوظة على config ويعيد بصمتها.
     * رخيص بما يكفي لاستدعائه قبل كل توليد: عامل الطابور يعيش طويلاً،
     * ولولا ذلك لبقي يستخدم المفتاح القديم حتى إعادة تشغيله.
     */
    public function apply(): string
    {
        $this->rememberEnvDefaults();

        $stored = $this->stored();
        $version = md5(serialize($stored));

        if ($version === $this->appliedVersion) {
            return $version;
        }

        foreach (self::FIELDS as $key => $field) {
            $value = array_key_exists($key, $stored) ? $this->reveal($key, $stored[$key]) : null;

            config([$field['config'] => filled($value) ? $value : $this->envDefaults[$key]]);
        }

        return $this->appliedVersion = $version;
    }

    /**
     * يحفظ القيم المرسلة. الحقل السري الفارغ يعني «أبقِ الحالي»،
     * لأن المفتاح لا يُعاد عرضه في النموذج أصلاً.
     */
    public function save(array $values, array $clear = [], ?int $userId = null): void
    {
        $this->rememberEnvDefaults();

        foreach (self::FIELDS as $key => $field) {
            if (in_array($key, $clear, true)) {
                PlatformSetting::whereKey($key)->delete();

                continue;
            }

            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = is_string($values[$key]) ? trim($values[$key]) : $values[$key];

            // غير السري المساوي لقيمة البيئة لا يُثبَّت: وإلا بقي النموذج القديم
            // محفوظاً إلى الأبد حتى بعد تحديث الافتراضي (كما حدث مع gemini-2.5-flash)
            if (! $field['secret'] && $value === ($this->envDefaults[$key] ?? null)) {
                $value = null;
            }

            if (blank($value)) {
                // غير السري الفارغ يعني العودة لقيمة البيئة
                if (! $field['secret']) {
                    PlatformSetting::whereKey($key)->delete();
                }

                continue;
            }

            PlatformSetting::updateOrCreate(['key' => $key], [
                'value' => $field['secret'] ? Crypt::encryptString($value) : $value,
                'is_secret' => $field['secret'],
                'updated_by' => $userId,
            ]);
        }

        $this->flush();
    }

    /**
     * القيمة الفعّالة الآن (محفوظة أو من البيئة). السرية لا تُعاد — استخدم mask().
     */
    public function value(string $key): ?string
    {
        $this->apply();

        return self::FIELDS[$key]['secret'] ? null : config(self::FIELDS[$key]['config']);
    }

    /**
     * مصدر القيمة: db = من الواجهة، env = من ملف البيئة، null = غير مضبوطة.
     */
    public function source(string $key): ?string
    {
        if (array_key_exists($key, $this->stored())) {
            return 'db';
        }

        $this->rememberEnvDefaults();

        return filled($this->envDefaults[$key] ?? null) ? 'env' : null;
    }

    /**
     * المفتاح بصيغة آمنة للعرض: أوله وآخره فقط.
     */
    public function mask(string $key): ?string
    {
        $this->apply();

        $value = (string) config(self::FIELDS[$key]['config']);

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) <= 12
            ? str_repeat('•', 8)
            : mb_substr($value, 0, 6).str_repeat('•', 8).mb_substr($value, -4);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->appliedVersion = null;
    }

    /**
     * القيم كما في قاعدة البيانات (السرية مشفّرة). تُخزَّن مؤقتاً مشفّرةً كذلك،
     * فلا يظهر مفتاح صريح في مخزن الكاش.
     */
    protected function stored(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::query()
                ->whereIn('key', array_keys(self::FIELDS))
                ->pluck('value', 'key')
                ->all());
        } catch (Throwable) {
            // قبل تشغيل الترحيل لا يوجد الجدول؛ المنصة تعمل بقيم البيئة وحدها
            return [];
        }
    }

    protected function reveal(string $key, ?string $value): ?string
    {
        if ($value === null || ! self::FIELDS[$key]['secret']) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // تغيّر APP_KEY: المفتاح المحفوظ صار غير مقروء، فنرجع للبيئة بدل الانهيار
            Log::warning("تعذر فك تشفير الإعداد [{$key}] — أعد إدخاله من صفحة الإعدادات.");

            return null;
        }
    }

    protected function rememberEnvDefaults(): void
    {
        if ($this->envDefaults !== null) {
            return;
        }

        foreach (self::FIELDS as $key => $field) {
            $this->envDefaults[$key] = config($field['config']);
        }
    }
}
