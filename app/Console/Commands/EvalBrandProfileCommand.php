<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Brand\BrandProfileGenerator;
use App\Services\Brand\Quality\ProfileQualityCheck;
use Illuminate\Console\Command;

/**
 * تقييم جودة توليد ملف الهوية على النموذج الحقيقي المضبوط في الإعدادات.
 *
 * يمر بنفس مسار الإنتاج حرفياً (BrandProfileGenerator::draft) على مشاريع
 * نموذجية ثابتة، ثم يفحص كل مخرج بـ ProfileQualityCheck. لا يحفظ شيئاً
 * ولا يخصم نقاطاً — لكنه يستهلك رصيد مزود الذكاء فعلاً (استدعاء لكل مشروع).
 *
 * شغّله بعد أي تعديل على البرومبت أو تبديل النموذج، وقارن الدرجات.
 */
class EvalBrandProfileCommand extends Command
{
    protected $signature = 'brand:eval-profile
                            {--sample=* : تشغيل مشاريع بعينها (coffee, consultancy, vague, constrained)}
                            {--show : عرض النصوص المولّدة كاملة}';

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

    public function handle(BrandProfileGenerator $generator, ProfileQualityCheck $check): int
    {
        $selected = $this->option('sample') ?: array_keys(self::SAMPLES);
        $unknown = array_diff($selected, array_keys(self::SAMPLES));

        if ($unknown !== []) {
            $this->error('مشاريع غير معروفة: '.implode('، ', $unknown));

            return self::INVALID;
        }

        $rows = [];
        $failed = 0;

        foreach ($selected as $key) {
            $sample = self::SAMPLES[$key];

            // علامة غير محفوظة: التقييم لا يلمس قاعدة البيانات
            $brand = new Brand(['dialect' => 'saudi', ...$sample['brand']]);

            $this->line("\n<fg=cyan>▶ {$key}</> — {$sample['answers']['project_name']}");

            try {
                $draft = $generator->draft($brand, $sample['answers']);
            } catch (\Throwable $e) {
                $this->error('  فشل الاستدعاء: '.$e->getMessage());
                $rows[] = [$key, '—', '—', '—', 'فشل', $e->getMessage()];
                $failed++;

                continue;
            }

            $report = $check->check($draft, $sample['answers'], (array) $brand->banned_words);

            $this->line("  النموذج: {$draft['model']} · {$draft['latency_ms']}ms");
            $this->line('  الكلمات: مبسط '.$check->words((string) $draft['simple'])
                .' · تفصيلي '.$check->words((string) $draft['detailed'])
                .' · ملاحظات '.count((array) ($draft['technical']['important_notes'] ?? [])));

            foreach ($report->issues() as $issue) {
                $color = $issue['severity'] === 'error' ? 'red' : 'yellow';
                $this->line("  <fg={$color}>[{$issue['severity']}] {$issue['field']}: {$issue['message']}</>");
            }

            if ($report->issues() === []) {
                $this->line('  <fg=green>لا مشاكل.</>');
            }

            if ($this->option('show')) {
                $this->newLine();
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

            $failed += $report->passes() ? 0 : 1;

            $rows[] = [
                $key,
                $report->score(),
                count($report->errors()),
                count($report->warnings()),
                $report->passes() ? 'ناجح' : 'راسب',
                implode(', ', $report->codes()) ?: '—',
            ];
        }

        $this->newLine();
        $this->table(['المشروع', 'الدرجة', 'أخطاء', 'تحذيرات', 'الحكم', 'الرموز'], $rows);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
