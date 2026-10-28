<?php

namespace App\Console\Commands;

use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Services\Brand\BrandProfileGenerator;
use App\Services\Brand\Quality\ProfileExpectations;
use App\Services\Brand\Quality\ProfileJudge;
use App\Services\Brand\Quality\ProfileQualityCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * تقييم جودة توليد ملف الهوية على النموذج الحقيقي المضبوط في الإعدادات.
 *
 * يمر بمسار الإنتاج حرفياً (BrandProfileGenerator::compose: مسودة ← بوابة الجودة
 * ← رئيس التحرير) على مشاريع نموذجية، ثم يفحص كل مخرج بـ ProfileQualityCheck.
 * لا يحفظ شيئاً ولا يخصم نقاطاً — لكنه يستهلك رصيد مزود الذكاء فعلاً.
 *
 * مجموعة الاختبار الكاملة (--dataset=tests/Evals/brand-profile.json) تضيف لكل حالة
 * توقعات مكتوبة قبل التشغيل (ProfileExpectations)، و--repeat يقيس الثبات،
 * و--judge يحكّم كل مخرج بنموذج من عائلة أخرى (ProfileJudge)، و--json يصدّر
 * النتائج كاملة للتحليل.
 *
 * شغّله بعد أي تعديل على البرومبت أو تبديل النموذج، وقارن الدرجات.
 */
class EvalBrandProfileCommand extends Command
{
    protected $signature = 'brand:eval-profile
                            {--sample=* : تشغيل مشاريع بعينها (coffee, consultancy, vague, constrained)}
                            {--dataset= : ملف حالات JSON بدل المشاريع المدمجة (tests/Evals/brand-profile.json)}
                            {--repeat=1 : عدد تشغيلات كل حالة، لقياس الثبات}
                            {--judge : تحكيم كل مخرج بنموذج من عائلة أخرى (config ai.eval_judge)}
                            {--json= : مسار ملف JSON بالنتائج كاملة للتحليل}
                            {--show : عرض النصوص المولّدة كاملة}
                            {--html= : مسار تقرير HTML (افتراضياً storage/app/brand-eval.html)}';

    protected $description = 'تقييم جودة توليد ملف الهوية على النموذج الحقيقي';

    /**
     * كل مشروع يختبر ميلاً مختلفاً عند النموذج:
     *   coffee       بيانات المرجع كاملة — الحالة المثالية
     *   consultancy  خدمة لا سلعة — هل تتغير الصياغة
     *   vague        إجابات شحيحة — أخصب أرض للاختلاق
     *   constrained  كلمات ممنوعة ورقم من المستخدم — هل يحترم القيود ويُبقي الرقم الصحيح
     */
    public const SAMPLES = [
        'coffee' => [
            'brand' => ['name' => 'امدادات القهوة'],
            'answers' => [
                'type' => 'good',
                'project_name' => 'امدادات القهوة',
                'store_url' => 'https://coffeesupplies.com.sa/ar',
                'one_liner' => 'نبيع مواد ومعدات الكافيهات والمطاعم مثل السيروب والصوص والحشوات والبودرة والقهوة بأنواعها والشوكولاتة وأدوات الباريستا وصيانة المكائن',
                'advantages' => "وكلاء لعدة علامات تجارية عالمية\nلدينا عدة فروع\nتوصيل مجاني للأنشطة التجارية داخل المدينة\nالبيع بالجملة والتجزئة",
                'audience' => 'أصحاب الكافيهات والمطاعم والباريستا في السعودية',
                'notes' => '',
            ],
        ],
        'consultancy' => [
            'brand' => ['name' => 'مسار للاستشارات', 'business_type' => 'service'],
            'answers' => [
                'type' => 'service',
                'project_name' => 'مسار للاستشارات',
                'store_url' => '',
                'one_liner' => 'نقدم استشارات تسويقية للمتاجر الإلكترونية الناشئة',
                'advantages' => "تقرير تحليل لحساباتك ومنافسيك\nخطة محتوى لشهر كامل\nجلسة متابعة بعد التنفيذ",
                'audience' => 'أصحاب المتاجر الإلكترونية الصغيرة على سلة وزد',
                'notes' => 'لا نقدم خدمة إدارة الحسابات، فقط الاستشارة والخطة',
            ],
        ],
        'vague' => [
            'brand' => ['name' => 'عطور ليان'],
            'answers' => [
                'type' => 'good',
                'project_name' => 'عطور ليان',
                'store_url' => '',
                'one_liner' => 'نبيع عطور',
                'advantages' => 'جودة عالية',
                'audience' => 'الجميع',
                'notes' => '',
            ],
        ],
        'constrained' => [
            'brand' => ['name' => 'مخابز السنبلة', 'banned_words' => ['رخيص', 'الأفضل', 'أفضل']],
            'answers' => [
                'type' => 'good',
                'project_name' => 'مخابز السنبلة',
                'store_url' => '',
                'one_liner' => 'مخبز يقدم الخبز البلدي والمعجنات والكيك يومياً',
                'advantages' => "15 فرعاً في الرياض\nخبز طازج كل صباح\nطلبات الولائم والمناسبات",
                'audience' => 'العائلات في الرياض ومنظمو المناسبات',
                'notes' => 'نحن الأرخص سعراً في الحي',
            ],
        ],
    ];

    public function handle(BrandProfileGenerator $generator, ProfileQualityCheck $check, ProfileExpectations $expectations, ProfileJudge $judge): int
    {
        $cases = $this->cases();

        if ($cases === null) {
            return self::INVALID;
        }

        $selected = $this->option('sample') ?: array_keys($cases);
        $unknown = array_diff($selected, array_keys($cases));

        if ($unknown !== []) {
            $this->error('مشاريع غير معروفة: '.implode('، ', $unknown));

            return self::INVALID;
        }

        $repeat = max(1, (int) $this->option('repeat'));
        $rows = [];
        $results = [];
        $failed = 0;

        foreach ($selected as $key) {
            for ($run = 1; $run <= $repeat; $run++) {
                $case = $cases[$key];
                $label = $repeat > 1 ? "{$key}#{$run}" : $key;
                $answers = $case['answers'];

                // علامة غير محفوظة: التقييم لا يلمس قاعدة البيانات
                $brand = new Brand(['dialect' => 'saudi', ...$case['brand']]);

                $this->line("\n<fg=cyan>▶ {$label}</> — {$answers['project_name']}");

                $result = [
                    'key' => $label,
                    'case' => $key,
                    'run' => $run,
                    'name' => $answers['project_name'],
                    'about' => $case['about'],
                    'meta' => $case['meta'],
                    'answers' => $this->answerSheet($answers),
                    'model' => null,
                    'error' => null,
                ];

                $startedAt = microtime(true);

                try {
                    $composed = $generator->compose($brand, $answers);
                } catch (\Throwable $e) {
                    $composed = $e;
                }

                if (! is_array($composed)) {
                    $error = $composed instanceof \Throwable ? $composed->getMessage() : 'لم يُنتج النموذج الوصفين المطلوبين.';

                    $this->error('  فشل الاستدعاء: '.$error);
                    $rows[] = [$label, '—', '—', '—', '—', '—', 'فشل', $error];
                    $results[] = [...$result, 'error' => $error];
                    $failed++;

                    continue;
                }

                ['draft' => $draft, 'report' => $report, 'first_report' => $first, 'attempts' => $attempts] = $composed;

                $expected = $expectations->check($case['expect'], $draft);
                $verdict = $this->option('judge') ? $this->judged($judge, $case, $answers, $draft) : null;
                $passes = $report->passes() && collect($expected)->doesntContain('severity', 'error');

                $results[] = [
                    ...$result,
                    'model' => $draft['model'],
                    'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'draft' => $draft,
                    'score' => $report->score(),
                    'errors' => count($report->errors()),
                    'warnings' => count($report->warnings()),
                    // التوقعات تظهر في التقرير مع مشاكل الفحص العام
                    'issues' => [...$report->issues(), ...$expected],
                    'attempts' => $attempts,
                    'first_issues' => $first->issues(),
                    'expectations' => $expected,
                    'passes' => $passes,
                    'judge' => $verdict,
                    'words' => [
                        'simple' => $check->words((string) $draft['simple']),
                        'detailed' => $check->words((string) $draft['detailed']),
                    ],
                ];

                $this->line("  النموذج: {$draft['model']} · محاولات {$attempts} · ".(($draft['polished'] ?? false) ? 'حرّره رئيس التحرير' : 'بلا تحرير'));
                $this->line('  الكلمات: مبسط '.$check->words((string) $draft['simple'])
                    .' · تفصيلي '.$check->words((string) $draft['detailed'])
                    .' · ملاحظات '.count((array) ($draft['technical']['important_notes'] ?? [])));

                foreach ([...$report->issues(), ...$expected] as $issue) {
                    $color = $issue['severity'] === 'error' ? 'red' : 'yellow';
                    $this->line("  <fg={$color}>[{$issue['severity']}] {$issue['field']}: {$issue['message']}</>");
                }

                if ($report->issues() === [] && $expected === []) {
                    $this->line('  <fg=green>لا مشاكل.</>');
                }

                if ($verdict && ! isset($verdict['error'])) {
                    $this->line("  المحكّم: {$verdict['overall']} / 5 · عيوب ".count($verdict['defects']));
                }

                if ($this->option('show')) {
                    $this->newLine();
                    // الموجز يشرح لماذا كُتب الوصفان هكذا: ضعفه يسبق ضعفهما
                    $this->line('  — الموجز الاستراتيجي —');
                    $this->line('  '.str_replace("\n", "\n  ", (string) ($draft['brief'] ?? '—')));
                    $this->line('  — المبسط —');
                    $this->line('  '.$draft['simple']);
                    $this->line('  — التفصيلي —');
                    $this->line('  '.$draft['detailed']);
                    $this->line('  — نوع النشاط — '.($draft['technical']['activity_type'] ?? ''));
                    $this->line('  — ملخص المبيعات — '.($draft['technical']['sales_summary'] ?? ''));
                    $this->line('  — توجيهات المزايا — '.($draft['technical']['advantages_directives'] ?? ''));
                    $this->line('  — الملاحظات —');
                    foreach ((array) ($draft['technical']['important_notes'] ?? []) as $i => $note) {
                        $this->line('    '.($i + 1).'. '.$note);
                    }
                }

                $failed += $passes ? 0 : 1;

                $rows[] = [
                    $label,
                    $report->score(),
                    count($report->errors()),
                    count($report->warnings()),
                    count($expected),
                    $verdict['overall'] ?? '—',
                    $passes ? 'ناجح' : 'راسب',
                    implode(', ', array_unique([...$report->codes(), ...array_column($expected, 'code')])) ?: '—',
                ];
            }
        }

        $this->newLine();
        $this->table(['المشروع', 'الدرجة', 'أخطاء', 'تحذيرات', 'توقعات', 'المحكّم', 'الحكم', 'الرموز'], $rows);

        $this->writeReport($results);
        $this->writeJson($results);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * فشل المحكّم لا يُسقط الحالة: المخرج قيس بالفحص الآلي، والتحكيم يُعاد لاحقاً.
     */
    protected function judged(ProfileJudge $judge, array $case, array $answers, array $draft): array
    {
        try {
            return $judge->judge($case, $answers, $draft);
        } catch (\Throwable $e) {
            $this->warn('  تعذّر التحكيم: '.$e->getMessage());

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * الحالات بشكل واحد: من ملف المجموعة إن مُرّر، وإلا المشاريع المدمجة.
     *
     * @return array<string, array{about: string, meta: array, brand: array, answers: array, expect: array}>|null
     */
    protected function cases(): ?array
    {
        $dataset = $this->option('dataset');

        if (blank($dataset)) {
            return collect(self::SAMPLES)
                ->map(fn ($sample) => ['about' => '', 'meta' => [], 'expect' => [], ...$sample])
                ->all();
        }

        $path = $this->absolute($dataset);
        $data = File::exists($path) ? json_decode(File::get($path), true) : null;

        if (! is_array($data['cases'] ?? null)) {
            $this->error("ملف الحالات غير موجود أو غير صالح: {$path}");

            return null;
        }

        $cases = [];

        foreach ($data['cases'] as $i => $case) {
            if (blank($case['key'] ?? null) || blank($case['answers']['project_name'] ?? null)) {
                $this->error('حالة بلا key أو project_name في الموضع '.($i + 1));

                return null;
            }

            $cases[$case['key']] = [
                'about' => (string) ($case['about'] ?? ''),
                'meta' => (array) ($case['meta'] ?? []),
                'brand' => ['name' => $case['answers']['project_name'], ...(array) ($case['brand'] ?? [])],
                'answers' => $case['answers'],
                'expect' => (array) ($case['expect'] ?? []),
            ];
        }

        return $cases;
    }

    protected function writeJson(array $results): void
    {
        if (blank($this->option('json'))) {
            return;
        }

        $path = $this->absolute($this->option('json'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'dataset' => $this->option('dataset'),
            'repeat' => max(1, (int) $this->option('repeat')),
            'judge_model' => $this->option('judge') ? config('ai.eval_judge.model') : null,
            'prompt_version' => BrandProfileGenerator::PROMPT_VERSION,
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->line('JSON:');
        $this->info($path);
    }

    protected function absolute(string $path): string
    {
        return preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) ? $path : base_path($path);
    }

    /**
     * تقرير HTML من اليمين لليسار في كل تشغيل.
     *
     * طرفية ويندوز (PowerShell) تعرض العربية معكوسة الحروف ومتقطعة، فلا
     * تصلح لقراءة الأوصاف ولا لمقارنتها بالمنافس. المتصفح يعرضها كما هي.
     */
    protected function writeReport(array $results): void
    {
        $path = $this->absolute($this->option('html') ?: storage_path('app/brand-eval.html'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, view('reports.brand-eval', [
            'results' => $results,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render());

        // المسار وحده في سطره: لاتيني فيُقرأ سليماً حتى في طرفية لا تعرض العربية
        $this->newLine();
        $this->line('Report / التقرير:');
        $this->info($path);
    }

    /**
     * @return array<string, string> السؤال ← الإجابة، كما رآها النموذج
     */
    protected function answerSheet(array $answers): array
    {
        $type = ProductType::tryFrom((string) ($answers['type'] ?? '')) ?? ProductType::Good;

        return collect(BrandProfile::sheetFor($answers, $type))
            ->mapWithKeys(fn ($row) => [$row['question'] => $row['answer']])
            ->all();
    }
}
