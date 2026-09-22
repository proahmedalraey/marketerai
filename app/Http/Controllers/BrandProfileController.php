<?php

namespace App\Http\Controllers;

use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Services\AI\ProviderException;
use App\Services\Brand\BrandProfileGenerator;
use App\Services\Brand\ClaimConflicts;
use Illuminate\Validation\ValidationException;
use App\Services\Brand\StorePagePrefill;
use App\Services\Credits\CreditService;
use App\Services\Credits\DailyCapReachedException;
use App\Services\Credits\InsufficientCreditsException;
use App\Support\CurrentBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * هوية العلامة: صفحة واحدة لكل ما يُكتب عن المشروع.
 *
 * فيها نوعان من المدخلات، والفرق بينهما هو ما يحدد سلوك الحفظ:
 *   • الإجابات: يولّد منها الذكاء الأوصاف، فتعديلها يجعل الأوصاف متأخرة.
 *   • إعدادات الكتابة: تُطبَّق على كل منشور كما هي، ولا تحتاج توليداً.
 *
 * كلاهما يُحفظ في أعمدة العلامة — المصدر الوحيد. النسخ تحفظ النصوص المولّدة
 * ولقطة الإجابات التي وُلدت منها فقط.
 */
class BrandProfileController extends Controller
{
    /** مفاتيح الإجابات — تُستخدم لتمييز أخطاء نموذج الإجابات عن غيرها. */
    protected const ANSWER_FIELDS = ['type', 'project_name', 'store_url', 'one_liner', 'advantages', 'audience', 'notes'];

    public function __construct(
        protected BrandProfileGenerator $generator,
        protected ClaimConflicts $conflicts,
    ) {}

    public function show(Request $request)
    {
        // مستخدم جديد بلا علامة يصل هنا مباشرة بعد التسجيل: الصفحة تنشئها
        $brand = CurrentBrand::get()?->load('defaultLogo');
        $profile = $brand ? BrandProfile::forBrand($brand)->active()->first() : null;
        $answers = $brand?->profileAnswers() ?? ['type' => ProductType::Good->value];

        return view('brands.profile', [
            'brand' => $brand,
            'profile' => $profile,
            'answers' => $answers,
            'sheet' => BrandProfile::sheetFor($answers, ProductType::from($answers['type'])),
            'stale' => $brand && $profile?->isStaleFor($brand),
            'versions' => $brand ? BrandProfile::forBrand($brand)->orderByDesc('version')->get() : collect(),
            'questions' => [
                ProductType::Good->value => BrandProfile::questions(ProductType::Good),
                ProductType::Service->value => BrandProfile::questions(ProductType::Service),
            ],
            'cost' => $brand ? $this->generator->cost($brand) : 0,
            // مجاني قبل إنشاء العلامة: لا محفظة يُخصم منها بعد
            'prefillCost' => $brand ? app(CreditService::class)->cost(StorePagePrefill::OPERATION) : 0,
            'jobUuid' => $request->query('job'),
            // مزود النص الذي يراه الموقع الآن: إن كان حقيقياً والنسخة تجريبية، فالطابور يعمل بإعدادات قديمة
            'textProvider' => rescue(fn () => app(\App\Services\AI\AiManager::class)->text()->name(), 'unknown', false),
            'editingAnswers' => $profile === null
                || (bool) $request->session()->get('errors')?->hasAny(self::ANSWER_FIELDS),
        ]);
    }

    /**
     * حفظ الإجابات. مجاني دائماً ولا يستدعي الذكاء — إلا أول مرة،
     * حيث لا أوصاف أصلاً والتوليد الأول مجاني، فلا معنى لخطوة إضافية.
     */
    public function saveAnswers(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(ProductType::class)],
            'project_name' => ['required', 'string', 'max:120'],
            'store_url' => ['nullable', 'url', 'max:255'],
            'one_liner' => ['required', 'string', 'max:1000'],
            'advantages' => ['required', 'string', 'max:1000'],
            'audience' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'type' => 'نوع النشاط',
            'project_name' => 'اسم المشروع',
            'store_url' => 'رابط المتجر',
            'one_liner' => 'وصف ما تبيعه',
            'advantages' => 'الميزة التنافسية',
            'audience' => 'الجمهور المستهدف',
            'notes' => 'الملاحظات الإضافية',
        ]);

        $user = $request->user();
        $brand = CurrentBrand::get() ?? new Brand(['user_id' => $user->id]);

        $brand->fill([
            'name' => $data['project_name'],
            'business_type' => $data['type'],
            'store_url' => $data['store_url'] ?? null,
            'description' => $data['one_liner'],
            // سطر لكل ميزة: الفاصلة داخل الجملة جزء منها لا حدّ بين ميزتين
            'selling_points' => $this->splitList($data['advantages'], '/\R+/u'),
            'audience' => $data['audience'],
            'profile_notes' => $data['notes'] ?? null,
            'onboarding_completed' => true,
        ])->save();

        if ($user->current_brand_id !== $brand->id) {
            $user->update(['current_brand_id' => $brand->id]);
            CurrentBrand::set($brand);
        }

        // حُفظت كما كتبها التاجر، لكن ما لن يُنفَّذ يُقال الآن لا بعد النشر (تدقيق المنافس C7)
        $warnings = collect(['one_liner', 'advantages', 'notes'])
            ->flatMap(fn ($key) => $this->conflicts->inStatement((string) ($data[$key] ?? '')))
            ->unique()
            ->values()
            ->all();

        // أول حفظ يولّد دائماً (مجاناً)؛ وبعده يولّد فقط إن طلب التاجر «حفظ وإعادة توليد»
        if (BrandProfile::forBrand($brand)->doesntExist() || $request->boolean('regenerate')) {
            return $this->dispatch($request, $brand)->with('warnings', $warnings);
        }

        return redirect()->route('brand.profile')
            ->with('status', 'حُفظت الإجابات. الأوصاف لا تعكسها بعد — أعد توليدها متى انتهيت من التعديل.')
            ->with('warnings', $warnings);
    }

    /** إعدادات الكتابة: تُطبَّق على المنشورات مباشرة، بلا توليد ولا نقاط. */
    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'dialect' => ['required', 'in:'.implode(',', array_keys(config('dialects')))],
            'tone' => ['nullable', 'string', 'max:255'],
            'banned_words' => ['nullable', 'string', 'max:500'],
            'content_rules' => ['nullable', 'string', 'max:2000'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
        ], [], [
            'dialect' => 'اللهجة',
            'tone' => 'نبرة العلامة',
            'banned_words' => 'الكلمات الممنوعة',
            'content_rules' => 'قواعدك',
            'whatsapp' => 'رقم واتساب',
        ]);

        $this->brand()->update([
            'dialect' => $data['dialect'],
            'tone' => $data['tone'] ?? null,
            'banned_words' => $this->splitList($data['banned_words'] ?? null),
            'content_rules' => $this->validatedRules($data['content_rules'] ?? null, 'content_rules') ?: null,
            'whatsapp' => $data['whatsapp'] ?? null,
        ]);

        return redirect()->route('brand.profile')->with('status', 'حُفظت إعدادات الكتابة. تُطبَّق من المنشور القادم.');
    }

    /**
     * «عبّئ من رابط المتجر»: اقتراحات للأسئلة يراجعها المستخدم قبل الحفظ.
     *
     * متزامن لا في طابور: المستخدم واقف أمام النموذج ينتظر الحقول،
     * كما في استيراد رابط منتج واحد. نقطة واحدة إن وُجد رصيد، ومجاني
     * للمستخدم الجديد الذي لم تُنشأ علامته بعد (والحد في المسار يمنع الإسراف).
     */
    public function prefill(Request $request, StorePagePrefill $prefill, CreditService $credits): JsonResponse
    {
        $data = $request->validate(['store_url' => ['required', 'string', 'max:255']], [], ['store_url' => 'رابط المتجر']);

        $brand = CurrentBrand::get();
        $held = 0;

        if ($brand) {
            try {
                $held = $credits->hold($brand, StorePagePrefill::OPERATION);
            } catch (InsufficientCreditsException|DailyCapReachedException $e) {
                return response()->json(['message' => $e->getMessage()], 402);
            }
        }

        try {
            $fields = $prefill->fromUrl($brand, $data['store_url']);
        } catch (\Throwable $e) {
            if ($brand && $held > 0) {
                $credits->refund($brand, $held, null, StorePagePrefill::OPERATION, 'فشل التعبئة من الرابط');
            }

            $message = match (true) {
                $e instanceof ProviderException => $e->userMessage(),
                $e instanceof \RuntimeException => $e->getMessage().($held > 0 ? ' لم تُخصم أي نقاط.' : ''),
                default => 'تعذّرت التعبئة من الرابط. لم تُخصم أي نقاط.',
            };

            return response()->json(['message' => $message], 422);
        }

        if ($brand && $held > 0) {
            $credits->settle($brand, $held, $held, null, StorePagePrefill::OPERATION);
        }

        return response()->json(['fields' => $fields, 'credits_charged' => $held]);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        return $this->dispatch($request, $this->brand());
    }

    /** تحرير وصف واحد في مكانه. */
    public function update(Request $request): RedirectResponse
    {
        $profile = BrandProfile::forBrand($this->brand())->active()->firstOrFail();

        $request->validate(['field' => ['required', 'in:simple,detailed,technical']]);

        $changes = match ($request->input('field')) {
            'simple' => $request->validate(
                ['simple' => ['required', 'string', 'max:800']], [], ['simple' => 'الوصف المبسط']
            ),
            'detailed' => $request->validate(
                ['detailed' => ['required', 'string', 'max:4000']], [], ['detailed' => 'الوصف التفصيلي']
            ),
            'technical' => ['technical' => $this->technicalFrom($request, (array) $profile->technical)],
        };

        $moved = [];

        if (isset($changes['technical'])) {
            [$changes['technical'], $moved] = $this->moveNewNotesToRules($changes['technical'], $profile);
        }

        $profile->applyManualEdit($changes);

        return redirect()->route('brand.profile')->with('status', 'حُفظ التعديل كنسخة يدوية. نسخة الذكاء السابقة باقية في السجل.'
            .($moved ? ' ونقلنا ما أضفته من ملاحظات إلى «قواعدك» في إعدادات الكتابة، لتبقى بعد إعادة التوليد.' : ''));
    }

    /**
     * الملاحظة التي يضيفها التاجر في الوصف التقني قاعدةٌ منه لا من التوليد.
     * لو بقيت في النسخة لمسحتها إعادة التوليد التالية، فتنتقل إلى قواعده.
     *
     * @return array{0: array, 1: array<int, string>}
     */
    protected function moveNewNotesToRules(array $technical, BrandProfile $profile): array
    {
        $brand = $this->brand();

        $fixed = array_map([BrandProfileGenerator::class, 'noteKey'], BrandProfileGenerator::FIXED_NOTES);
        $known = array_map([BrandProfileGenerator::class, 'noteKey'], $profile->constraints());

        // القيود الثابتة لا تُحذف ولا تُكرر: يكتبها الكود في كل نسخة
        $submitted = array_values(array_filter(
            (array) ($technical['important_notes'] ?? []),
            fn ($note) => ! in_array(BrandProfileGenerator::noteKey($note), $fixed, true),
        ));

        $new = array_values(array_filter(
            $submitted,
            fn ($note) => ! in_array(BrandProfileGenerator::noteKey($note), $known, true),
        ));

        if ($new !== []) {
            $this->validatedRules(implode("\n", $new), 'technical.important_notes');

            $brand->update(['content_rules' => collect([...(array) $brand->content_rules, ...$new])
                ->unique(fn ($rule) => BrandProfileGenerator::noteKey($rule))
                ->values()
                ->all()]);
        }

        $technical['important_notes'] = [
            ...BrandProfileGenerator::FIXED_NOTES,
            ...array_values(array_diff($submitted, $new)),
        ];

        return [$technical, $new];
    }

    /**
     * قواعد الكتابة سطراً سطراً. ما لن يُنفَّذ يُرفض بسببه الآن،
     * لا يُحفظ ثم يُتجاهل في كل منشور دون أن يعرف التاجر (تدقيق المنافس C7).
     *
     * @return array<int, string>
     */
    protected function validatedRules(?string $text, string $field): array
    {
        $rules = collect($this->splitList($text, '/\R+/u'))
            ->map(fn ($rule) => \Illuminate\Support\Str::limit($rule, 200, ''))
            ->take(12)
            ->values()
            ->all();

        $problems = collect($rules)
            ->map(fn ($rule) => ($reason = $this->conflicts->inRule($rule))
                ? '«'.\Illuminate\Support\Str::limit($rule, 50).'» — '.$reason
                : null)
            ->filter()
            ->values()
            ->all();

        if ($problems !== []) {
            throw ValidationException::withMessages([$field => $problems]);
        }

        return $rules;
    }

    /**
     * «حذف والبدء من جديد»: يحذف الأوصاف ونسخها كلها.
     * الإجابات تبقى لأنها بيانات العلامة نفسها — يبدأ المستخدم منها لا من الصفر.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $brand = $this->brand();

        // كتابة الاسم بدل نقرة: الحذف يمسح كل النسخ، ولا رجعة فيه
        $request->validate(
            ['confirm_name' => ['required', 'string', Rule::in([$brand->name])]],
            ['confirm_name.in' => 'الاسم المكتوب لا يطابق اسم العلامة.'],
            ['confirm_name' => 'اسم العلامة'],
        );

        BrandProfile::forBrand($brand)->delete();

        return redirect()->route('brand.profile')->with('status', 'حُذفت الأوصاف بكل نسخها. راجع إجاباتك ثم أنشئ ملفاً جديداً.');
    }

    /** الاستعادة تُرجع الأوصاف فقط — الإجابات والحقائق تبقى على حالها الحالية. */
    public function restore(int $version): RedirectResponse
    {
        $profile = BrandProfile::forBrand($this->brand())->where('version', $version)->firstOrFail();

        if ($profile->is_active) {
            return back();
        }

        $restored = $profile->restoreAsNewVersion();

        return redirect()->route('brand.profile')
            ->with('status', "استُعيدت أوصاف النسخة {$version} كنسخة جديدة رقم {$restored->version}.");
    }

    public function destroyVersion(int $version): RedirectResponse
    {
        $profile = BrandProfile::forBrand($this->brand())->where('version', $version)->firstOrFail();

        if ($profile->is_active) {
            return back()->withErrors(['version' => 'لا يمكن حذف النسخة المستخدمة حالياً. استعد نسخة أخرى أولاً.']);
        }

        $profile->delete();

        return back()->with('status', "حُذفت النسخة {$version}.");
    }

    protected function dispatch(Request $request, Brand $brand): RedirectResponse
    {
        try {
            $job = $this->generator->dispatch($brand, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        }

        return redirect()->route('brand.profile', ['job' => $job->uuid]);
    }

    /**
     * حقول الوصف التقني القابلة للتحرير.
     * النوع والاسم والرابط حقائق تُقرأ حيّةً من العلامة: تُعدَّل من الإجابات.
     */
    protected function technicalFrom(Request $request, array $current): array
    {
        $data = $request->validate([
            'technical.activity_type' => ['nullable', 'string', 'max:300'],
            'technical.sales_summary' => ['nullable', 'string', 'max:1500'],
            'technical.advantages_directives' => ['nullable', 'string', 'max:1500'],
            'technical.important_notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'technical.activity_type' => 'نوع النشاط',
            'technical.sales_summary' => 'ملخص المبيعات',
            'technical.advantages_directives' => 'توجيهات المزايا التنافسية',
            'technical.important_notes' => 'الملاحظات المهمة',
        ])['technical'] ?? [];

        return [
            ...$current,
            'activity_type' => trim((string) ($data['activity_type'] ?? '')),
            'sales_summary' => trim((string) ($data['sales_summary'] ?? '')),
            'advantages_directives' => trim((string) ($data['advantages_directives'] ?? '')),
            'important_notes' => collect(preg_split('/\R+/u', (string) ($data['important_notes'] ?? '')))
                ->map(fn ($note) => trim($note))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /** يحوّل نصاً مفصولاً بفواصل أو أسطر إلى مصفوفة نظيفة. */
    protected function splitList(?string $value, string $separators = '/[\n,،]+/u'): array
    {
        if (blank($value)) {
            return [];
        }

        return collect(preg_split($separators, $value))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }
}
