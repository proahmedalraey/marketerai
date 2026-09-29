<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * قائمة النماذج المتاحة فعلاً لمفتاح المزود، من واجهته مباشرة.
 *
 * القوائم الثابتة تتقادم: المزودون يسحبون النماذج باستمرار
 * (Gemini أوقف 2.5 للحسابات الجديدة)، فالمصدر الوحيد الموثوق هو المزود نفسه.
 * تُخزَّن مؤقتاً ساعة لكل مفتاح، والفشل لا يُخزَّن حتى يظهر الإصلاح فوراً.
 */
class ModelCatalog
{
    /**
     * @return array{text: string[], image: string[], speech?: string[]}|null null = تعذر الجلب (لا مفتاح، أو خطأ)
     */
    public function for(string $provider): ?array
    {
        $config = config("ai.providers.{$provider}");

        // قائمة OpenRouter عامة ولا تحتاج مفتاحاً، فيرى المدير النماذج قبل أن يلصق مفتاحه
        if (! $config || (blank($config['api_key'] ?? null) && $provider !== 'openrouter')) {
            return null;
        }

        $cacheKey = 'ai_models.'.$provider.'.'.md5(($config['api_key'] ?? '').'|'.$config['base_url']);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $models = match ($provider) {
                'gemini' => $this->gemini($config),
                'openai' => $this->openai($config),
                'anthropic' => $this->anthropic($config),
                'openrouter' => $this->openrouter($config),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        if ($models) {
            Cache::put($cacheKey, $models, now()->addHour());
        }

        return $models;
    }

    /**
     * قدرات نموذج صور في OpenRouter: الخيارات التي يقبلها وقيمها
     * (aspect_ratio, resolution, quality, input_references...). null = تعذر الجلب.
     */
    public function imageParameters(string $model): ?array
    {
        $all = $this->openrouterImageModels();

        return $all[$model]['supported_parameters'] ?? null;
    }

    /** @return array<string, array>  id => بيانات النموذج كما يعيدها OpenRouter */
    protected function openrouterImageModels(): array
    {
        $base = rtrim((string) config('ai.providers.openrouter.base_url'), '/');

        $cacheKey = 'ai_models.openrouter.image_index.'.md5($base);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)->get($base.'/images/models');
        } catch (Throwable) {
            return [];
        }

        // نحتفظ بالقدرات فقط: أوصاف النماذج طويلة ولا حاجة لتخزينها
        $index = $response->failed() ? [] : collect($response->json('data', []))
            ->mapWithKeys(fn ($m) => [$m['id'] => ['supported_parameters' => $m['supported_parameters'] ?? []]])
            ->all();

        // الفشل لا يُخزَّن: القائمة الفارغة المخزنة كانت ستحجب الصور ساعة كاملة
        if ($index !== []) {
            Cache::put($cacheKey, $index, now()->addHour());
        }

        return $index;
    }

    protected function openrouter(array $config): ?array
    {
        $base = rtrim($config['base_url'], '/');

        $response = Http::timeout(10)->get($base.'/models');

        if ($response->failed()) {
            return null;
        }

        $text = collect($response->json('data', []))
            // نصوص فقط: نماذج الصور لها قائمتها، والنماذج متعددة المخرجات لا تصلح لكتابة منشور
            ->filter(fn ($m) => ($m['architecture']['output_modalities'] ?? []) === ['text'])
            // :batch نسخ غير فورية؛ التوليد هنا ينتظر ردّاً حياً
            ->reject(fn ($m) => str_ends_with($m['id'], ':batch'))
            // المنصة تطلب JSON منظماً في كل توليد تقريباً
            ->filter(fn ($m) => array_intersect(['response_format', 'structured_outputs'], $m['supported_parameters'] ?? []))
            ->sortByDesc(fn ($m) => $m['created'] ?? 0)
            ->pluck('id')
            ->values()
            ->all();

        // قائمة الصور المخصصة أدق من /models: فيها كل نماذج الصور لا بعضها
        $image = array_keys($this->openrouterImageModels());

        if ($image === []) {
            $image = collect($response->json('data', []))
                ->filter(fn ($m) => in_array('image', $m['architecture']['output_modalities'] ?? [], true))
                ->pluck('id')->values()->all();
        }

        return $text === [] ? null : ['text' => $text, 'image' => $image];
    }

    protected function gemini(array $config): ?array
    {
        $response = Http::withHeaders(['x-goog-api-key' => $config['api_key']])
            ->timeout(8)
            ->get(rtrim($config['base_url'], '/').'/models', ['pageSize' => 1000]);

        if ($response->failed()) {
            return null;
        }

        $all = collect($response->json('models', []))
            ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
            ->map(fn ($m) => str_replace('models/', '', $m['name']));

        // Gemini ونانو بنانا فقط: Lyria للموسيقى وGemma وVeo ليست لهذا الاستخدام،
        // والصوت والتفريغ والتضمين والروبوتات لا تكتب منشوراً
        $ids = $all
            ->filter(fn ($id) => preg_match('/^(gemini-|nano-banana)/', $id))
            ->reject(fn ($id) => preg_match('/tts|audio|live|transcribe|omni|embedding|robotics|computer-use|aqa|learnlm/', $id));

        // نماذج النطق للتعليق الصوتي: قائمتها الخاصة، الأحدث أولاً
        $speech = $all->filter(fn ($id) => preg_match('/^gemini-.*-tts/', $id))->values()->all();
        rsort($speech, SORT_NATURAL);

        $split = $this->split($ids->all(), fn ($id) => str_contains($id, 'image') || str_starts_with($id, 'nano-banana'));

        return $split ? $split + ['speech' => $speech] : null;
    }

    protected function openai(array $config): ?array
    {
        $response = Http::withToken($config['api_key'])
            ->timeout(8)
            ->get(rtrim($config['base_url'], '/').'/models');

        if ($response->failed()) {
            return null;
        }

        $ids = collect($response->json('data', []))
            ->pluck('id')
            ->filter(fn ($id) => preg_match('/^(gpt-|o\d|chatgpt-|dall-e)/', $id))
            ->reject(fn ($id) => preg_match('/audio|realtime|transcribe|tts|search|embedding|moderation|instruct/', $id));

        return $this->split($ids->all(), fn ($id) => str_contains($id, 'image') || str_starts_with($id, 'dall-e'));
    }

    protected function anthropic(array $config): ?array
    {
        $response = Http::withHeaders([
            'x-api-key' => $config['api_key'],
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(8)
            ->get(rtrim($config['base_url'], '/').'/models', ['limit' => 100]);

        if ($response->failed()) {
            return null;
        }

        // Anthropic ترتّبها من الأحدث، فنحافظ على ترتيبها
        return ['text' => collect($response->json('data', []))->pluck('id')->values()->all(), 'image' => []];
    }

    protected function split(array $ids, callable $isImage): ?array
    {
        if ($ids === []) {
            return null;
        }

        // الأحدث أولاً: ترتيب طبيعي عكسي يضع 3.8 قبل 3.6 قبل 2.5
        rsort($ids, SORT_NATURAL);

        return [
            'text' => array_values(array_filter($ids, fn ($id) => ! $isImage($id))),
            'image' => array_values(array_filter($ids, $isImage)),
        ];
    }
}
