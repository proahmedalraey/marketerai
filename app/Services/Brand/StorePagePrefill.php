<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\Import\Support\Fetcher;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * يعبّئ أسئلة الهوية من صفحة المتجر.
 *
 * الإجابات الشحيحة («نبيع عطور»، «جودة عالية») هي المصدر الأول لأوصاف
 * عامة ومختلَقة. صفحة المتجر فيها غالباً ما لم يكتبه صاحبه: الفئات
 * والمزايا والجمهور. نستخرجها مقترحاتٍ يراجعها قبل الحفظ — لا نحفظ شيئاً هنا.
 */
class StorePagePrefill
{
    public const OPERATION = 'brand.prefill';

    /** ما يكفي النموذج لفهم المتجر دون أن نرسل صفحة كاملة بتكلفتها. */
    protected const MAX_TEXT_CHARS = 6000;

    public function __construct(
        protected Fetcher $fetcher,
        protected AiManager $ai,
    ) {}

    /**
     * @return array{project_name: string, one_liner: string, advantages: string, audience: string}
     *
     * @throws RuntimeException برسالة تصلح للعرض
     */
    public function fromUrl(?Brand $brand, string $url): array
    {
        $url = trim($url);
        $url = Str::startsWith($url, ['http://', 'https://']) ? $url : 'https://'.ltrim($url, '/');

        $html = $this->fetcher->get($this->fetcher->assertPublicUrl($url));

        if (blank($html)) {
            throw new RuntimeException('تعذّرت قراءة الصفحة. تأكد أن الرابط عام ويفتح بلا تسجيل دخول.');
        }

        $page = $this->readable($html);

        if (mb_strlen($page) < 80) {
            throw new RuntimeException('الصفحة لا تحوي نصاً كافياً نستخرج منه. جرّب رابط الصفحة الرئيسية أو صفحة «من نحن».');
        }

        $response = $this->ai->generateText(new TextRequest(
            system: $this->system(),
            prompt: "## نص صفحة المتجر\nالرابط: {$url}\n\n{$page}",
            schema: [
                'type' => 'object',
                'properties' => [
                    'project_name' => ['type' => 'string'],
                    'one_liner' => ['type' => 'string'],
                    'advantages' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'audience' => ['type' => 'string'],
                ],
                'required' => ['project_name', 'one_liner', 'advantages', 'audience'],
            ],
            temperature: 0.2,
            maxTokens: 800,
            operation: self::OPERATION,
        ));

        $data = (array) ($response->data ?? []);

        $advantages = collect(is_array($data['advantages'] ?? null) ? $data['advantages'] : preg_split('/\R+/u', (string) ($data['advantages'] ?? '')))
            ->map(fn ($a) => trim(preg_replace('/^\s*(\d+[.)-]|[-•*])\s*/u', '', (string) $a)))
            ->filter()
            ->take(6)
            ->implode("\n");

        $fields = [
            'project_name' => Str::limit(trim((string) ($data['project_name'] ?? '')), 120, ''),
            'one_liner' => Str::limit(trim((string) ($data['one_liner'] ?? '')), 1000, ''),
            'advantages' => Str::limit($advantages, 1000, ''),
            'audience' => Str::limit(trim((string) ($data['audience'] ?? '')), 1000, ''),
        ];

        if (array_filter($fields) === []) {
            throw new RuntimeException('لم نجد في الصفحة ما يكفي لتعبئة الأسئلة. اكتبها يدوياً.');
        }

        return $fields;
    }

    protected function system(): string
    {
        return <<<'SYSTEM'
        تستخرج من نص صفحة متجر إجابات أسئلة تعريفية بالمشروع، ليراجعها صاحبه قبل الحفظ.

        قواعد ملزمة:
        - استخرج فقط ما هو مذكور في النص. ما لم يُذكر اتركه نصاً فارغاً "".
        - لا تخمّن جمهوراً أو ميزة لم تُذكر — الفراغ أفضل من التخمين.
        - اكتب بالعربية حتى لو كانت الصفحة بالإنجليزية، وأبقِ أسماء العلامات كما هي.
        - لا عبارات تسويقية ولا مبالغات؛ جمل خبرية قصيرة.

        الحقول:
        - project_name: اسم المتجر كما يظهر.
        - one_liner: سطر واحد: ماذا يبيع المتجر أو يقدّم، بذكر الفئات الرئيسية.
        - advantages: من 2 إلى 5 مزايا مذكورة صراحة (توصيل، ضمان، وكالة، فروع…)، ميزة لكل عنصر.
        - audience: لمن يبيع، إن ذُكر.
        SYSTEM;
    }

    /**
     * نص الصفحة المقروء: العنوان والوصف والعناوين والفقرات، بلا شيفرة ولا قوائم تنقّل.
     */
    protected function readable(string $html): string
    {
        $meta = [];

        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $meta[] = 'العنوان: '.trim(html_entity_decode(strip_tags($m[1])));
        }

        foreach (['description', 'og:description', 'og:site_name'] as $name) {
            if (preg_match('#<meta[^>]+(?:name|property)=["\']'.preg_quote($name, '#').'["\'][^>]*content=["\']([^"\']+)#i', $html, $m)) {
                $meta[] = "{$name}: ".trim(html_entity_decode($m[1]));
            }
        }

        $body = preg_replace('#<(script|style|noscript|svg|nav|footer|header|form|iframe)\b.*?</\1>#is', ' ', $html);
        $body = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $body);
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", trim($text));

        // الأسطر المكررة (قوائم منتجات، أزرار «أضف للسلة») تُحذف: تضخّم بلا معنى
        $lines = collect(explode("\n", $text))
            ->map(fn ($l) => trim($l))
            ->filter(fn ($l) => mb_strlen($l) > 2)
            ->unique()
            ->implode("\n");

        return Str::limit(implode("\n", $meta)."\n\n".$lines, self::MAX_TEXT_CHARS, '');
    }
}
