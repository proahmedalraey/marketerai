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
     * @return array{text: string[], image: string[]}|null  null = تعذر الجلب (لا مفتاح، أو خطأ)
     */
    public function for(string $provider): ?array
    {
        $config = config("ai.providers.{$provider}");

        if (! $config || blank($config['api_key'] ?? null)) {
            return null;
        }

        $cacheKey = 'ai_models.'.$provider.'.'.md5($config['api_key'].'|'.$config['base_url']);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $models = match ($provider) {
                'gemini' => $this->gemini($config),
                'openai' => $this->openai($config),
                'anthropic' => $this->anthropic($config),
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

    protected function gemini(array $config): ?array
    {
        $response = Http::withHeaders(['x-goog-api-key' => $config['api_key']])
            ->timeout(8)
            ->get(rtrim($config['base_url'], '/').'/models', ['pageSize' => 1000]);

        if ($response->failed()) {
            return null;
        }

        $ids = collect($response->json('models', []))
            ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
            ->map(fn ($m) => str_replace('models/', '', $m['name']))
            // Gemini ونانو بنانا فقط: Lyria للموسيقى وGemma وVeo ليست لهذا الاستخدام،
            // والصوت والتفريغ والتضمين والروبوتات لا تكتب منشوراً
            ->filter(fn ($id) => preg_match('/^(gemini-|nano-banana)/', $id))
            ->reject(fn ($id) => preg_match('/tts|audio|live|transcribe|omni|embedding|robotics|computer-use|aqa|learnlm/', $id));

        return $this->split($ids->all(), fn ($id) => str_contains($id, 'image') || str_starts_with($id, 'nano-banana'));
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
