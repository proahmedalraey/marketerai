<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\Contracts\TextProvider;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;

/**
 * مزود وهمي يعمل بلا مفاتيح API.
 * يتيح تشغيل المنصة كاملة في التطوير والاختبار دون تكلفة،
 * ويحترم نفس مخطط المخرجات حتى تنكشف أخطاء البنية مبكراً.
 */
class FakeTextProvider implements TextProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-text-1';
    }

    public function generate(TextRequest $request): TextResponse
    {
        usleep(300_000);

        $data = $this->buildFrom($request);

        return new TextResponse(
            raw: json_encode($data, JSON_UNESCAPED_UNICODE),
            data: $request->schema ? $data : null,
            provider: $this->name(),
            model: $this->model(),
            tokensIn: (int) round(mb_strlen($request->prompt) / 3),
            tokensOut: 320,
            latencyMs: 300,
        );
    }

    protected function buildFrom(TextRequest $request): array
    {
        $properties = array_keys($request->schema['properties'] ?? []);

        /*
         * مخطط المحتوى يُعرف بحقوله (كابشن/شرائح/سكربت).
         * أي مخطط آخر نبنيه من خصائصه نفسها، فلا يحتاج كل مخطط جديد
         * فرعاً خاصاً هنا ويفشل صامتاً حين ننساه.
         */
        if ($properties !== [] && ! array_intersect($properties, ['caption', 'slides', 'script', 'scenes', 'frames', 'tweets'])) {
            return collect($properties)
                ->mapWithKeys(fn (string $key) => [$key => $this->placeholder($key)])
                ->all();
        }

        // أشكال «كتابة المحتوى»: مشاهد وإطارات وتغريدات وأقسام بالعدد الذي يطلبه المخطط
        if ($structured = $this->structured($request->schema['properties'])) {
            return $structured;
        }

        $slideCount = preg_match_all('/سلايد|شريحة/u', $request->prompt) ?: 5;

        $slides = [];
        $roles = ['hook', 'promise', 'pull', 'harvest', 'ask'];

        for ($i = 0; $i < min(max($slideCount, 5), 7); $i++) {
            $role = $roles[min($i, count($roles) - 1)];
            $slides[] = [
                'role' => $role,
                'text' => "[نص تجريبي · {$role}] هذه مخرجات المزود الوهمي للتطوير. بدّل AI_TEXT_PROVIDER في ملف البيئة لتشغيل نموذج حقيقي.",
            ];
        }

        return [
            'caption' => '[كابشن تجريبي] المنصة تعمل والمزود الوهمي مفعّل. اضبط مفاتيح المزود الحقيقي لتحصل على محتوى فعلي.',
            'slides' => $slides,
            'hashtags' => ['#تجريبي', '#المنصة_تعمل', '#محتوى'],
            'script' => '[سكربت تجريبي] مشهد افتتاحي · لقطة قريبة · خاتمة بدعوة واحدة.',
            'image_prompts' => ['A clean product photo on a neutral background, soft studio light.'],
        ];
    }

    protected function structured(array $properties): ?array
    {
        $count = fn (string $key) => max((int) ($properties[$key]['minItems'] ?? 3), 1);
        $hashtags = ['#تجريبي', '#المنصة_تعمل'];
        $note = 'بدّل AI_TEXT_PROVIDER في ملف البيئة لتشغيل نموذج حقيقي.';
        $filming = fn (string $key) => isset($properties[$key]['items']['properties']['shot']);

        if (isset($properties['scenes'])) {
            $seconds = 0;

            return [
                'hook' => '[افتتاحية تجريبية] جملة الثانيتين الأوليين.',
                'scenes' => array_map(function ($i) use (&$seconds, $filming, $note) {
                    $from = $seconds;
                    $seconds += 4;

                    return array_filter([
                        'time' => "{$from}-{$seconds}",
                        'voiceover' => "[كلام تجريبي · المشهد {$i}] {$note}",
                        'on_screen' => "نص الشاشة {$i}",
                        'shot' => $filming('scenes') ? 'لقطة قريبة بإضاءة طبيعية من نافذة، والكاميرا ثابتة على حامل.' : null,
                    ]);
                }, range(1, $count('scenes'))),
                'caption' => '[وصف تجريبي] يُنشر مع الفيديو.',
                'hashtags' => $hashtags,
            ];
        }

        if (isset($properties['frames'])) {
            return ['frames' => array_map(fn ($i) => array_filter([
                'text' => "[نص تجريبي · الإطار {$i}]",
                'visual' => 'صورة المنتج على خلفية هادئة.',
                'interaction' => $i === 1 ? 'استفتاء: جربته؟ نعم / ليس بعد' : null,
                'shot' => $filming('frames') ? 'تصوير رأسي باليد، لقطة متوسطة.' : null,
            ]), range(1, $count('frames')))];
        }

        if (isset($properties['tweets'])) {
            return [
                'tweets' => array_map(fn ($i) => "[تغريدة تجريبية {$i}] {$note}", range(1, $count('tweets'))),
                'hashtags' => ['#تجريبي'],
            ];
        }

        if (isset($properties['sections'])) {
            return [
                'title' => '[عنوان تجريبي للإنفوجرافيك]',
                'sections' => array_map(fn ($i) => ['heading' => "قسم {$i}", 'text' => "[سطر تجريبي {$i}]"], range(1, $count('sections'))),
                'caption' => '[وصف تجريبي] يُنشر مع الإنفوجرافيك.',
                'hashtags' => $hashtags,
            ];
        }

        return null;
    }

    protected function placeholder(string $key): string
    {
        return match ($key) {
            'summary' => "[وصف تجريبي] نقطة أولى عن المنتج.
[وصف تجريبي] نقطة ثانية.",
            'features' => "[مميزة تجريبية أولى]
[مميزة تجريبية ثانية]",
            'specifications' => "الوزن: [قيمة تجريبية]
الفئة: [قيمة تجريبية]",
            'audience' => '[جمهور تجريبي] اضبط مفاتيح المزود الحقيقي لمخرجات فعلية.',
            default => "[نص تجريبي · {$key}]",
        };
    }

}
