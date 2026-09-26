<?php

namespace App\Services\Content;

use App\Models\GenerationJob;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\ProviderException;
use App\Support\Arabic\ArabicText;
use App\Support\WordDiff;
use Illuminate\Support\Facades\Log;

/**
 * مدقق لغوي يصحح الإملاء والنحو في المنشور ولا يمس غيرهما.
 *
 * تدقيق المنافس رصد «أصلح» بدل «أصبح» و«نلتشف» بدل «نكشف» (C2، C7) —
 * أخطاء لا يلتقطها فحص حتمي. المدقق نموذج هو الآخر، فلا يُؤتمن: كل حقل
 * يُقارن بأصله، وما تجاوز حدود التصحيح (رقم تغيّر، اسم لاتيني تغيّر،
 * جملة أُعيدت صياغتها) يُرفض ويبقى الأصل.
 */
class Proofreader
{
    public const OPERATION = 'content.proofread';

    public function __construct(protected AiManager $ai) {}

    /**
     * @param  array  $content  مخرج ContentSchema::normalize
     * @return array{content: array, changes: array<int, array{field: string, before: string, after: string}>, skipped: array<int, string>}
     */
    public function proofread(array $content, string $dialect, ?GenerationJob $job = null, ?array $only = null): array
    {
        // إعادة كتابة شريحة واحدة تُدقَّق وحدها: باقي الشرائح لم يمسّها أحد
        $fields = $only === null
            ? $this->fields($content)
            : array_intersect_key($this->fields($content), array_flip($only));

        if ($fields === []) {
            return ['content' => $content, 'changes' => [], 'skipped' => []];
        }

        $request = new TextRequest(
            system: $this->system($dialect),
            prompt: $this->prompt($fields),
            schema: $this->schema(array_keys($fields)),
            temperature: 0.1,
            maxTokens: 2048,
            operation: self::OPERATION,
            model: config('ai.proofread.model'),
        );

        $provider = config('ai.proofread.provider');

        try {
            $response = $this->ai->generateText($request, $job, $provider);
        } catch (ProviderException $e) {
            // نموذج التدقيق مكتوب بخطأ أو سُحب أو ليس لهذا المزود: لا نُسقط التدقيق كله بسببه
            if ($request->model === null || ! in_array($e->statusCode, [400, 404], true)) {
                throw $e;
            }

            Log::warning('نموذج التدقيق اللغوي غير صالح؛ أُعيد الطلب بنموذج المزود الافتراضي', [
                'model' => $request->model,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            $request->model = null;
            $response = $this->ai->generateText($request, $job, $provider);
        }

        return $this->merge($content, $fields, (array) ($response->data ?? []));
    }

    /**
     * النصوص التي تُدقَّق، بمفاتيح يفهمها النموذج (slide_2) ويقابلها
     * حقل الفحص (slide:2). الهاشتاقات وبرومبتات الصور ليست نصاً يُقرأ.
     *
     * @return array<string, string>
     */
    protected function fields(array $content): array
    {
        $fields = ['caption' => (string) ($content['caption'] ?? '')];

        foreach (array_values($content['slides'] ?? []) as $index => $slide) {
            $fields['slide_'.($index + 1)] = (string) ($slide['text'] ?? '');
        }

        $fields['script'] = (string) ($content['script'] ?? '');
        $fields['hook'] = (string) ($content['hook'] ?? '');

        // أشكال «كتابة المحتوى»: ما يُقال ويُقرأ فقط، لا توقيت ولا توجيه تصوير
        foreach (array_values($content['scenes'] ?? []) as $index => $scene) {
            $fields['scene_'.($index + 1)] = (string) ($scene['voiceover'] ?? '');
            $fields['screen_'.($index + 1)] = (string) ($scene['on_screen'] ?? '');
        }

        foreach (array_values($content['frames'] ?? []) as $index => $frame) {
            $fields['frame_'.($index + 1)] = (string) ($frame['text'] ?? '');
        }

        foreach (array_values($content['tweets'] ?? []) as $index => $tweet) {
            $fields['tweet_'.($index + 1)] = (string) $tweet;
        }

        $fields['title'] = (string) ($content['title'] ?? '');

        foreach (array_values($content['sections'] ?? []) as $index => $section) {
            $fields['section_'.($index + 1)] = (string) ($section['text'] ?? '');
        }

        return array_filter($fields, fn ($text) => trim($text) !== '');
    }

    /**
     * يقبل من كل حقل تصحيحه إن بقي تصحيحاً، ويُبقي الأصل إن صار إعادة كتابة.
     *
     * @param  array<string, string>  $fields
     * @return array{content: array, changes: array<int, array{field: string, before: string, after: string}>, skipped: array<int, string>}
     */
    protected function merge(array $content, array $fields, array $corrected): array
    {
        $share = (float) config('ai.proofread.max_changed_share', 0.2);
        $maxEdit = (int) config('ai.proofread.max_edit_words', 3);

        $changes = [];
        $skipped = [];

        foreach ($fields as $key => $original) {
            $candidate = is_string($corrected[$key] ?? null) ? trim($corrected[$key]) : '';
            $field = preg_replace('/^([a-z]+)_(\d+)$/', '$1:$2', $key);

            if ($candidate === '' || $candidate === trim($original)) {
                continue;
            }

            $diff = WordDiff::compare($original, $candidate);

            // تغيّر في الفراغات وحدها لا يستحق أن يمس تنسيق الأسطر
            if ($diff['changes'] === []) {
                continue;
            }

            $rewrote = $diff['changed'] > max(2, (int) ceil($share * $diff['total']))
                || collect($diff['changes'])->contains(fn ($c) => $this->words($c['before']) > $maxEdit || $this->words($c['after']) > $maxEdit);

            if ($rewrote || $this->signature($original) !== $this->signature($candidate)) {
                $skipped[] = $field;

                continue;
            }

            $content = $this->put($content, $key, $candidate, $diff['changes']);

            foreach ($diff['changes'] as $change) {
                $changes[] = ['field' => $field] + $change;
            }
        }

        return compact('content', 'changes', 'skipped');
    }

    /** الأرقام والأسماء اللاتينية بترتيبها: تصحيح الإملاء لا يغيّر أياً منها. */
    protected function signature(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)*|[A-Za-z][A-Za-z0-9]*/u', ArabicText::digits($text), $m);

        return $m[0];
    }

    /**
     * @param  array<int, array{before: string, after: string}>  $changes
     */
    protected function put(array $content, string $key, string $text, array $changes = []): array
    {
        if (preg_match('/^slide_(\d+)$/', $key, $m)) {
            $index = (int) $m[1] - 1;
            $content['slides'][$index]['text'] = $text;

            // طبقات الهوك تُرسم على الصورة: التصحيح يصلها كما وصل النص
            foreach (['kicker', 'focal', 'tail'] as $part) {
                if (isset($content['slides'][$index][$part])) {
                    foreach ($changes as $change) {
                        if ($change['before'] !== '') {
                            $content['slides'][$index][$part] = str_replace($change['before'], $change['after'], $content['slides'][$index][$part]);
                        }
                    }
                }
            }
        } elseif (preg_match('/^(scene|screen|frame|tweet|section)_(\d+)$/', $key, $m)) {
            $index = (int) $m[2] - 1;

            match ($m[1]) {
                'scene' => $content['scenes'][$index]['voiceover'] = $text,
                'screen' => $content['scenes'][$index]['on_screen'] = $text,
                'frame' => $content['frames'][$index]['text'] = $text,
                'tweet' => $content['tweets'][$index] = $text,
                'section' => $content['sections'][$index]['text'] = $text,
            };
        } else {
            $content[$key] = $text;
        }

        return $content;
    }

    protected function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    protected function system(string $dialect): string
    {
        $label = config("dialects.{$dialect}.label", 'سعودية بيضاء');

        return <<<SYSTEM
        أنت مدقق لغوي عربي. تصلح الأخطاء الواضحة فقط في نص تسويقي جاهز للنشر.

        صحّح:
        - الكلمة المكتوبة بحرف خاطئ («أصلح» يُقصد بها «أصبح»، «نلتشف» يُقصد بها «نكشف»).
        - الهمزات، والتاء المربوطة والهاء، والألف المقصورة والياء.
        - الكلمة المكررة سهواً، والتركيب المكسور نحوياً.

        لا تغيّر:
        - اللهجة ({$label}) ومفرداتها: الكلمة العامية المقصودة ليست خطأ.
        - الأسلوب والصياغة وترتيب الجمل وطولها.
        - الأرقام والأسعار والأسماء والكلمات اللاتينية والإيموجي وفواصل الأسطر.

        لا تضف كلمة ولا معلومة، ولا تحذف معلومة. النص السليم يُعاد كما هو حرفياً.
        SYSTEM;
    }

    /** @param array<string, string> $fields */
    protected function prompt(array $fields): string
    {
        return "النصوص (JSON):\n"
            .json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nأعد كائن JSON بنفس المفاتيح، وقيمة كل مفتاح هي النص نفسه بعد تصحيحه.";
    }

    /** @param array<int, string> $keys */
    protected function schema(array $keys): array
    {
        return [
            'type' => 'object',
            'properties' => collect($keys)->mapWithKeys(fn ($key) => [$key => ['type' => 'string']])->all(),
            'required' => $keys,
        ];
    }
}
