<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use App\Services\AI\ProviderException;
use App\Services\Content\ContentGenerationService;
use App\Services\Content\ContentRequirements;
use Illuminate\Console\Command;

/**
 * تقييم صدق المحتوى على النموذج الحقيقي المضبوط في الإعدادات.
 *
 * يعيد تجارب تدقيق المنافس (docs/sahal-audit-plan.md) على نفس البيانات،
 * ويمر بمسار الإنتاج حرفياً (ContentGenerationService::generateVersion):
 * نفس البرومبت، نفس الفحص، نفس طلب التصحيح والتدقيق اللغوي. ثم يقارن أخطاء المسودة
 * الأولى بأخطاء ما كان سيُحفظ — هذا مقياس المرحلة 1.
 *
 * لا يحفظ شيئاً ولا يخصم نقاطاً، لكنه يستهلك رصيد المزود فعلاً:
 * طلب لكل نسخة، وطلب ثانٍ لكل نسخة احتاجت تصحيحاً، وطلب تدقيق لغوي لكل نسخة.
 */
class EvalContentCommand extends Command
{
    protected $signature = 'content:eval
                            {--sample=* : تشغيل سيناريوهات بعينها}
                            {--show : عرض النصوص المحفوظة كاملة}';

    protected $description = 'تقييم صدق المحتوى المولّد قبل بوابة الصدق وبعدها';

    /** العلامتان من التدقيق: «بن الديرة» (مختلقة للاختبار) و«امدادات القهوة» (متجر حقيقي). */
    public const BRANDS = [
        'deira' => [
            'name' => 'بن الديرة',
            'description' => 'حبوب قهوة مختصة محمصة وأدوات تحضير منزلية',
            'selling_points' => ['طحن حسب طريقة التحضير عند الطلب'],
            'audience' => 'محبو القهوة المختصة في المنزل',
            'dialect' => 'msa',
            'banned_words' => ['تجربة', 'رخيص'],
        ],
        'supplies' => [
            'name' => 'امدادات القهوة',
            'description' => 'نبيع مواد ومعدات الكافيهات والمطاعم كالسيروب والصوص والحشوات والبودرة والقهوة وأدوات الباريستا',
            'selling_points' => [
                'وكلاء لعدة علامات تجارية عالمية ومحامص محلية',
                'لدينا عدة فروع',
                'مناديب توصيل مجاني للأنشطة التجارية في نفس المدينة',
                'البيع بالجملة والتجزئة',
            ],
            'audience' => 'أصحاب الكافيهات والمطاعم ومحلات الحلويات',
            'dialect' => 'saudi',
        ],
    ];

    public const PRODUCTS = [
        // المنتج (أ) في التدقيق: مكتمل
        'yirgacheffe' => [
            'type' => 'good',
            'title' => 'حبوب يرقاشيفي إثيوبية',
            'features' => "درجة تحميص فاتحة\nنكهات الياسمين والليمون والتوت",
            'specifications' => 'الوزن: 250 جرام',
            'price' => 75,
            'currency' => 'SAR',
        ],
        // المنتج (ب) في التدقيق: ناقص عمداً — أخصب أرض للاختلاق
        'grinder' => [
            'type' => 'good',
            'title' => 'مطحنة قهوة يدوية',
            'features' => 'مطحنة يدوية للاستخدام المنزلي',
        ],
        'bisan' => [
            'type' => 'good',
            'title' => 'حشوة كريمة البندق البيضاء من بيسان',
            'features' => "حشوة كريمة البندق البيضاء من العلامة التجارية بيسان\nمناسبة للاستخدام في المقاهي والمطاعم",
            'specifications' => "الوزن: 5 كجم\nالعلامة التجارية: بيسان",
            'price' => 120,
            'currency' => 'SAR',
        ],
    ];

    /** كل سيناريو يعيد تجربة من التدقيق، والوصف يسمّيها. */
    public const SAMPLES = [
        'carousel' => [
            'about' => 'C1 — كاروسيل بيع مباشر بفصحى، وكلمة «تجربة» ممنوعة',
            'brand' => 'deira', 'product' => 'yirgacheffe', 'versions' => 1,
            'input' => ['goal' => 'direct_sales', 'platform' => 'instagram', 'template' => 'marketing_carousel'],
        ],
        'incomplete' => [
            'about' => 'C2 — نفس الكاروسيل لمنتج ناقص البيانات',
            'brand' => 'deira', 'product' => 'grinder', 'versions' => 1,
            'input' => ['goal' => 'direct_sales', 'platform' => 'instagram', 'template' => 'marketing_carousel'],
        ],
        'x' => [
            'about' => 'C3 — تغريدة إكس بفصحى',
            'brand' => 'deira', 'product' => 'yirgacheffe', 'versions' => 1,
            'input' => ['goal' => 'direct_sales', 'platform' => 'x', 'template' => 'problem_solution'],
        ],
        'batch' => [
            'about' => 'C4 — ثلاث نسخ بنفس الإعدادات (حيث اخترع المنافس أسعاراً وعملاء)',
            'brand' => 'deira', 'product' => 'yirgacheffe', 'versions' => 3,
            'input' => ['goal' => 'direct_sales', 'platform' => 'instagram', 'template' => 'marketing_carousel'],
        ],
        'national_day' => [
            'about' => 'C6 — اليوم الوطني بلا عرض حقيقي (حيث اخترع المنافس كود KSA96)',
            'brand' => 'deira', 'product' => 'yirgacheffe', 'versions' => 1,
            'input' => ['goal' => 'reach', 'platform' => 'instagram', 'template' => 'seasonal', 'occasion' => 'اليوم الوطني السعودي 96'],
        ],
        'supplies' => [
            'about' => 'متجر يوصّل فعلاً — التوصيل المجاني مسموح ولا يُعدّ اختلاقاً',
            'brand' => 'supplies', 'product' => 'bisan', 'versions' => 1,
            'input' => ['goal' => 'direct_sales', 'platform' => 'instagram', 'template' => 'problem_solution'],
        ],
    ];

    public function handle(ContentGenerationService $service): int
    {
        $selected = $this->option('sample') ?: array_keys(self::SAMPLES);
        $unknown = array_diff($selected, array_keys(self::SAMPLES));

        if ($unknown !== []) {
            $this->error('سيناريوهات غير معروفة: '.implode('، ', $unknown).'. المتاح: '.implode('، ', array_keys(self::SAMPLES)));

            return self::INVALID;
        }

        $rows = [];
        $total = $dirtyBefore = $dirtyAfter = 0;

        foreach ($selected as $key) {
            $sample = self::SAMPLES[$key];

            // نماذج غير محفوظة: التقييم لا يلمس قاعدة البيانات
            $brand = new Brand(self::BRANDS[$sample['brand']]);
            $product = new Product(self::PRODUCTS[$sample['product']]);
            $builder = $service->builderFor($brand, $product, $sample['input']);

            $angles = $sample['versions'] > 1
                ? array_slice(ContentRequirements::anglesFor($brand, $product), 0, $sample['versions'])
                : [null];

            $this->line("\n<fg=cyan>▶ {$key}</> — {$sample['about']}");

            foreach ($angles as $angle) {
                $label = $key.($angle ? " · {$angle}" : '');

                try {
                    $version = $service->generateVersion((clone $builder)->angle($angle));
                } catch (\Throwable $e) {
                    $message = $e instanceof ProviderException ? $e->userMessage() : $e->getMessage();
                    $this->error("  {$label}: فشل الاستدعاء — {$message}");
                    $rows[] = [$label, '—', '—', '—', '—', 'فشل'];

                    // الحصة نفدت: كل طلب تالٍ سيُرفض بنفس السبب
                    if ($e instanceof ProviderException && $e->quotaExhausted) {
                        $this->warn('توقف التقييم: حصة المزود نفدت. أعد التشغيل بعد تجددها أو بمزود آخر من إعدادات المنصة.');

                        break 2;
                    }

                    continue;
                }

                if ($version === null) {
                    $this->warn("  {$label}: مخرج غير صالح للاستخدام");
                    $rows[] = [$label, '—', '—', '—', '—', 'غير صالح'];

                    continue;
                }

                $before = $version['first_report'];
                $after = $version['report'];

                $total++;
                $dirtyBefore += $before->passes() ? 0 : 1;
                $dirtyAfter += $after->passes() ? 0 : 1;

                $this->line("  <fg=gray>{$label}</> المسودة: ".count($before->errors()).' أخطاء · المحفوظ: '.count($after->errors()).' أخطاء');

                foreach ($after->issues() as $issue) {
                    $color = $issue['severity'] === 'error' ? 'red' : 'yellow';
                    $this->line("    <fg={$color}>[{$issue['severity']}] {$issue['field']}: {$issue['message']}</>");
                }

                $proof = $version['proofread'] ?? null;

                foreach ($proof['changes'] ?? [] as $change) {
                    $this->line("    <fg=green>[تدقيق] {$change['field']}: «{$change['before']}» ← «{$change['after']}»</>");
                }

                if ($this->option('show')) {
                    foreach ($version['content']['slides'] as $i => $slide) {
                        $this->line('    '.($i + 1).". [{$slide['role']}] {$slide['text']}");
                    }

                    $this->line('    — '.$version['content']['caption']);
                    $this->line('    # '.implode(' #', $version['content']['hashtags']));
                }

                $rows[] = [
                    $label,
                    count($before->errors()),
                    count($after->errors()),
                    $version['attempts'],
                    match ($proof['status'] ?? null) {
                        'applied' => count($proof['changes']).' تصحيح',
                        'rejected' => 'رُفض',
                        'failed' => 'فشل',
                        'clean' => 'سليم',
                        default => '—',
                    },
                    implode(', ', $after->codes()) ?: '—',
                ];
            }
        }

        $this->newLine();
        $this->table(['السيناريو', 'أخطاء المسودة', 'أخطاء المحفوظ', 'المحاولات', 'التدقيق اللغوي', 'الرموز المتبقية'], $rows);

        if ($total > 0) {
            $this->line(sprintf(
                'نسخ فيها خطأ: قبل البوابة %d من %d (%d%%) · بعدها %d من %d (%d%%)',
                $dirtyBefore, $total, round(100 * $dirtyBefore / $total),
                $dirtyAfter, $total, round(100 * $dirtyAfter / $total),
            ));
        }

        return $dirtyAfter === 0 && $total > 0 ? self::SUCCESS : self::FAILURE;
    }
}
