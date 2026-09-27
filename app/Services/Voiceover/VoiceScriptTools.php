<?php

namespace App\Services\Voiceover;

use App\Enums\JobStatus;
use App\Jobs\VoiceScriptJob;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\Credits\CreditService;
use App\Support\Arabic\ArabicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * أدوات نص التعليق الصوتي — طلبات نصية صغيرة تمر بالمسار الملزم (مهمة + طابور + AiManager):
 *
 *   enhance     «تحسين الصوت»: وسوم أداء ([short pause]، [excited]…) وعلامات ترقيم تضبط الإلقاء
 *   diacritize  «تشكيل»: حركات تضبط النطق
 *   direction   «توليد تلقائي» للأسلوب المخصص: توجيهات إلقاء قصيرة من النص نفسه
 *
 * الأولى والثانية تعدّلان نص التاجر، فيحرسهما شرط واحد صارم: كلمات النص بعد حذف الوسوم
 * والتشكيل هي كلمات الأصل نفسها وبترتيبها. أي إضافة أو حذف أو تبديل = إعادة للنموذج مرة،
 * ثم فشل وإرجاع النقاط بدل تسليم نص قال ما لم يقله التاجر (بوابة الصدق بصيغتها الأضيق).
 */
class VoiceScriptTools
{
    public const OPERATION = 'voice.script';

    public const TOOLS = ['enhance', 'diacritize', 'direction'];

    public function __construct(
        protected AiManager $ai,
        protected CreditService $credits,
        protected VoiceCatalog $catalog,
    ) {}

    /** @param  array{text: string, style?: ?string, language?: ?string}  $input */
    public function dispatch(Brand $brand, string $tool, array $input, ?int $userId = null): GenerationJob
    {
        return DB::transaction(function () use ($brand, $tool, $input, $userId) {
            $job = GenerationJob::create([
                'brand_id' => $brand->id,
                'user_id' => $userId,
                'type' => 'voice_script',
                'status' => JobStatus::Queued,
                'payload' => [
                    'tool' => $tool,
                    'text' => trim($input['text']),
                    'style' => $input['style'] ?? null,
                    'language' => $input['language'] ?? 'ar',
                ],
            ]);

            $this->credits->hold($brand, self::OPERATION, 1, $job);

            VoiceScriptJob::dispatch($job->id)->onQueue('content');

            return $job->fresh();
        });
    }

    public function run(GenerationJob $job): void
    {
        $payload = (array) $job->payload;
        $tool = $payload['tool'];
        $original = (string) $payload['text'];

        $job->markProcessing();

        $result = null;
        $problems = null;

        foreach ([0.5, 0.2] as $temperature) {
            $response = $this->ai->generateText(new TextRequest(
                system: $this->system($tool),
                prompt: $this->userPrompt($tool, $payload, $problems),
                schema: ['type' => 'object', 'required' => ['text'], 'properties' => ['text' => ['type' => 'string']]],
                temperature: $temperature,
                maxTokens: $tool === 'direction' ? 400 : 3000,
                operation: self::OPERATION,
                model: config('ai.voice_script.model'),
            ), $job);

            $candidate = $this->clean($tool, (string) ($response->data['text'] ?? ''));
            $problems = $this->problems($tool, $original, $candidate);

            if ($problems === []) {
                $result = $candidate;

                break;
            }
        }

        $held = (float) $job->credits_held;

        if ($result === null) {
            $this->credits->refund($job->brand, $held, $job, self::OPERATION, 'إرجاع: تعذّر تجهيز النص بأمان');
            $job->markFailed(match ($tool) {
                'direction' => 'تعذّر اقتراح توجيه مناسب. اكتبه بنفسك أو جرّب مرة أخرى.',
                default => 'تعذّر تجهيز النص دون تغيير كلماتك، فأبقيناه كما هو. جرّب مرة أخرى.',
            });

            return;
        }

        $this->credits->settle($job->brand, $held, $held, $job, self::OPERATION);
        $job->markCompleted(['text' => $result, 'tool' => $tool]);
    }

    protected function system(string $tool): string
    {
        $tags = collect(config('voiceover.performance_tags', []))->map(fn ($t) => "[{$t}]")->implode(' ');

        return match ($tool) {
            'enhance' => <<<TXT
أنت مخرج أداء صوتي. تجهّز نص التاجر ليُقرأ بصوت معلّق محترف قراءةً طبيعية مقنعة، دون أن تغيّر كلمة واحدة منه.

المسموح فقط:
1. وسوم أداء بالإنجليزية بين معقوفين من هذه القائمة حصراً: {$tags}
   ضع الوسم قبل الجملة التي يلوّنها، أو مكان الوقفة. وسم لكل جملة أو جملتين يكفي؛ لا تُكثر.
2. علامات ترقيم تضبط الإلقاء (، . ؟ ! …) وتقسيم الأسطر عند الانتقال بين الأفكار.

الممنوع: حذف أي كلمة أو إضافتها أو تبديلها أو إعادة ترتيبها، وتحويل الأرقام إلى كلمات، وتصحيح الإملاء، والتشكيل، وأي وسم خارج القائمة.
أعد النص كاملاً بعد التجهيز في الحقل المطلوب.
TXT,
            'diacritize' => <<<'TXT'
أنت مدقق لغوي متخصص في التشكيل. شكّل نص التاجر ليُنطق صحيحاً بصوت آلي: حركات الكلمات كلها في الفصحى، وفي العامية ما يلتبس نطقه فقط وكما يُنطق في لهجته — لا تحوّل العامية إلى فصحى.

لا تغيّر أي حرف أو كلمة أو ترتيب، ولا الأرقام، ولا علامات الترقيم. اترك ما بين المعقوفين [ ] كما هو حرفياً.
أعد النص كاملاً مشكولاً في الحقل المطلوب.
TXT,
            'direction' => <<<'TXT'
أنت مخرج إعلانات صوتية. اكتب توجيهات إلقاء قصيرة لمعلّق صوتي سيقرأ نص التاجر: النبرة، والسرعة، ومستوى الطاقة، وأين يضغط، وأين يتوقف.

جملتان إلى ثلاث بالعربية، حتى 50 كلمة، بلا عناوين ولا قوائم ولا علامات اقتباس.
لا تُعِد كتابة النص ولا تقتبس منه، ولا تذكر أرقاماً أو عروضاً، ولا أسماء أصوات أو نماذج.
أعد التوجيهات وحدها في الحقل المطلوب.
TXT,
        };
    }

    /** @param  list<string>|null  $feedback */
    protected function userPrompt(string $tool, array $payload, ?array $feedback): string
    {
        $style = filled($payload['style'] ?? null) && $payload['style'] !== 'custom'
            ? "\n\nأسلوب الإلقاء المختار: ".$this->catalog->styleLabel($payload['style'])
            : '';

        $language = ($payload['language'] ?? 'ar') === 'en' ? "\nلغة النص: الإنجليزية." : '';

        return "نص التاجر:\n«{$payload['text']}»{$style}{$language}"
            .($feedback ? "\n\n## تصحيح مطلوب\nنسختك السابقة خالفت القواعد:\n- ".implode("\n- ", $feedback)."\nأعدها دون هذه المخالفات." : '');
    }

    protected function clean(string $tool, string $text): string
    {
        $text = trim($text);
        $text = trim($text, "«»\"“”");

        if ($tool === 'direction') {
            return Str::squish(preg_replace('/[*_`#]+/u', '', $text));
        }

        if ($tool === 'enhance') {
            // وسم خارج القائمة قد يُنطق حرفياً: يُحذف بدل أن يُسلَّم
            $allowed = array_map('mb_strtolower', config('voiceover.performance_tags', []));
            $text = preg_replace_callback('/\[([^\]\n]{1,40})\]/u', fn ($m) => in_array(mb_strtolower(trim($m[1])), $allowed, true) ? '['.trim($m[1]).']' : '', $text);
        }

        $text = preg_replace('/[ \t]+/u', ' ', $text);

        return trim(preg_replace('/ *\R */u', "\n", $text));
    }

    /**
     * ما يخالف الأمانة، قائمة تُعاد للنموذج كما هي. فارغة = سليم.
     *
     * @return list<string>
     */
    protected function problems(string $tool, string $original, string $candidate): array
    {
        if ($candidate === '') {
            return ['لم تُعد أي نص.'];
        }

        if ($tool === 'direction') {
            return mb_strlen($candidate) > 400 ? ['التوجيه أطول من اللازم: اختصره في جملتين أو ثلاث.'] : [];
        }

        $before = static::words($original);
        $after = static::words($candidate);

        if ($before === $after) {
            return [];
        }

        // أول موضع اختلاف: يُسمّى للنموذج ليصلحه بعينه
        $index = collect($before)->keys()->first(fn ($i) => ($after[$i] ?? null) !== $before[$i]) ?? count($before);
        $expected = $before[$index] ?? null;
        $got = $after[$index] ?? null;

        return [match (true) {
            $expected === null => "أضفت كلمة («{$got}») ليست في نص التاجر: احذفها.",
            $got === null => "حذفت كلمة («{$expected}») من نص التاجر: أعدها في مكانها.",
            default => "بدّلت «{$expected}» بـ«{$got}»: أعد كلمة التاجر كما هي.",
        }];
    }

    /**
     * كلمات النص للمقارنة: بلا وسوم ولا تشكيل ولا ترقيم، وبهمزات موحّدة.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $text = preg_replace('/\[[^\]\n]{1,40}\]/u', ' ', $text);

        return array_values(array_map(
            fn (string $token) => ArabicText::normalize($token),
            array_filter(ArabicText::tokens($text), fn ($t) => ArabicText::normalize($t) !== ''),
        ));
    }
}
