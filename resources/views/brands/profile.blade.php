@extends('layouts.app')
@section('title', 'هوية العلامة')
@section('subtitle', 'ماذا تبيع، ولمن، وبأي نبرة — منها يُكتب كل منشور')

@php
    use App\Models\BrandProfile;

    // الحقائق (النوع والاسم والرابط) تُقرأ حيّةً من العلامة لا من النسخة
    $technical = $profile ? $profile->technicalFor($brand) : [];
    $costLabel = $cost > 0 ? $cost.' نقطة' : 'مجاناً';

    // ثلاثة مصادر للقيود، تُعرض منفصلة لأن مصيرها عند إعادة التوليد مختلف:
    // الثابتة يكتبها النظام دائماً، والمشتقة يعيد كتابتها التوليد، وقواعد التاجر لا يمسّها
    $fixedKeys = array_map([\App\Services\Brand\BrandProfileGenerator::class, 'noteKey'], \App\Services\Brand\BrandProfileGenerator::FIXED_NOTES);
    $notes = collect($profile?->constraints() ?? [])
        ->reject(fn ($n) => in_array(\App\Services\Brand\BrandProfileGenerator::noteKey($n), $fixedKeys, true))
        ->values()
        ->all();
    $rules = (array) ($brand?->content_rules ?? []);
    $kept = collect($profile?->quality['kept'] ?? [])
        ->map(fn ($f) => BrandProfile::TECHNICAL_LABELS[$f] ?? ($f === 'important_notes' ? 'الملاحظات المهمة' : $f))
        ->all();
@endphp

@section('content')
<div
    class="space-y-4 max-w-4xl"
    x-data="{ answersOpen: @js($editingAnswers) }"
    @close-answers="answersOpen = false"
>

    {{-- ================= حالة التوليد الجارية ================= --}}
    @if ($jobUuid)
        <div class="card p-4" x-data="jobTracker(@js($jobUuid), { redirectTo: @js(route('brand.profile')) })">
            <div class="flex items-center justify-between gap-2 mb-3">
                <h2 class="flex items-center gap-2 text-sm font-bold text-fg">
                    <x-icon name="refresh" class="w-4 h-4 text-brand-600 dark:text-brand-400" />
                    نبني ملف مشروعك
                </h2>
                <span class="chip-info" x-text="label">في الانتظار</span>
            </div>

            <div
                class="h-2 rounded-full bg-muted overflow-hidden"
                role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100"
                aria-label="تقدّم التوليد"
            >
                <div
                    class="h-full rounded-full transition-[width] duration-500 ease-out"
                    :class="failed ? 'bg-danger' : (timedOut ? 'bg-warning' : 'bg-brand-500')"
                    :style="`width: ${progress}%`"
                ></div>
            </div>

            <p class="mt-2.5 text-xs leading-relaxed" role="status" aria-live="polite">
                <span x-show="!finished" class="text-fg-muted">نكتب الأوصاف الثلاثة من إجاباتك… عادة أقل من دقيقة.</span>
                <span x-show="finished && !failed && !timedOut" x-cloak class="text-success-fg">اكتمل الملف. نعرضه الآن…</span>
                <span x-show="failed" x-cloak class="text-danger-fg" x-text="error || 'تعذّر التوليد وأُرجعت نقاطك كاملة. جرّب مجدداً.'"></span>
                <span x-show="timedOut" x-cloak class="text-warning-fg">يستغرق أطول من المعتاد. نكمل في الخلفية — حدّث الصفحة بعد دقائق. إن تعذّر التوليد تُرجع نقاطك تلقائياً.</span>
            </p>
        </div>
    @endif

    {{-- ================= الأوصاف متأخرة عن الإجابات ================= --}}
    @if ($stale && ! $jobUuid)
        <div class="alert-warning items-center flex-wrap" role="status">
            <x-icon name="alert-circle" class="w-5 h-5 shrink-0" />
            <p class="flex-1 min-w-[12rem] leading-relaxed">
                <strong class="font-semibold">الأوصاف لا تعكس آخر تعديلاتك على الإجابات.</strong>
                أعد توليدها حين تنتهي من التعديل.
            </p>
            <form method="POST" action="{{ route('brand.profile.regenerate') }}">
                @csrf
                <button type="submit" class="btn-primary btn-sm">
                    <x-icon name="refresh" class="w-4 h-4" />
                    <span>إعادة توليد الأوصاف</span>
                    <span class="chip bg-white/15 text-white !py-0.5 tnum">{{ $costLabel }}</span>
                </button>
            </form>
        </div>
    @endif

    @if ($profile)

        {{-- ================= رأس المشروع ================= --}}
        <div class="card px-5 py-3.5 flex flex-wrap items-center gap-x-4 gap-y-2">
            <p class="flex items-center gap-2 text-sm min-w-0">
                <span class="text-fg-subtle">مشروعك:</span>
                <span class="font-semibold text-fg">{{ $technical['project_type'] ?? $profile->projectType()->label() }}</span>
                <span class="w-px h-4 bg-line" aria-hidden="true"></span>
                <span class="font-bold text-fg truncate">{{ $technical['project_name'] ?? $brand->name }}</span>
            </p>

            <a href="#versions" class="ms-auto chip-quiet hover:border-line-strong hover:text-fg transition">
                <x-icon name="history" class="w-3.5 h-3.5" />
                <span>النسخة <span class="tnum">{{ $profile->version }}</span> · {{ $profile->source->label() }}</span>
            </a>

            {{-- متى تُكتب الأوصاف: كان غامضاً، فصار مكتوباً فوقها --}}
            <p class="w-full text-xs text-fg-muted leading-relaxed">
                يكتب الذكاء الاصطناعي الأوصاف الثلاثة من «الأسئلة والإجابات» أدناه: أول مرة عند حفظ إجاباتك،
                ثم كلما ضغطت «إعادة توليد الأوصاف» أو «حفظ وإعادة توليد». تعديل الإجابات بـ«حفظ فقط» لا يغيّرها.
                <span class="text-fg-subtle">آخر توليد {{ $profile->created_at->locale('ar')->diffForHumans() }}.</span>
            </p>
        </div>

        {{-- نص تجريبي من المزود الوهمي: ليس أوصافاً، والصفحة تقول ذلك وتقول لماذا --}}
        @if ($profile->isPlaceholder() && ! $jobUuid)
            <div class="alert-danger" role="alert">
                <x-icon name="alert-circle" class="w-5 h-5 shrink-0 mt-px" />
                <div class="flex-1 min-w-0 space-y-2">
                    <p class="font-semibold">هذه ليست أوصاف مشروعك: هي نص تجريبي لم يكتبه الذكاء الاصطناعي.</p>

                    @if ($textProvider !== 'fake')
                        <p class="leading-relaxed">
                            النموذج مضبوط في الإعدادات، لكن عامل الطابور — البرنامج الذي ينفّذ التوليد في الخلفية —
                            بدأ قبل ضبطه، فبقي يعمل بالمزود التجريبي. أغلق نافذة «Marketer Ai - الطابور»،
                            ثم شغّل <code dir="ltr" class="font-mono text-xs">start.bat</code> من جديد، ثم أعد التوليد.
                        </p>
                    @else
                        <p class="leading-relaxed">
                            لا يوجد نموذج ذكاء اصطناعي مفعّل للمنصة بعد.
                            @can('manage-platform')
                                فعّله من <a href="{{ route('settings.ai') }}" class="underline font-medium">إعدادات الذكاء الاصطناعي</a>، ثم أعد التوليد.
                            @else
                                تواصل مع مدير المنصة لتفعيله.
                            @endcan
                        </p>
                    @endif

                    <form method="POST" action="{{ route('brand.profile.regenerate') }}">
                        @csrf
                        <button type="submit" class="btn-primary btn-sm">
                            <x-icon name="refresh" class="w-4 h-4" />
                            <span>أعد التوليد الآن · مجاناً</span>
                        </button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ================= ما التقطه فحص الجودة ================= --}}
        @if ($issues = $profile->visibleIssues())
            @php
                $fieldLabels = [
                    'simple' => 'الوصف المبسط', 'detailed' => 'الوصف التفصيلي',
                    'activity_type' => 'نوع النشاط', 'sales_summary' => 'ملخص المبيعات',
                    'advantages_directives' => 'توجيهات المزايا', 'important_notes' => 'الملاحظات المهمة',
                ];
            @endphp

            <div class="alert-warning" role="status">
                <x-icon name="alert-circle" class="w-5 h-5 shrink-0 mt-px" />
                <div class="flex-1 min-w-0">
                    <p class="font-semibold">راجع هذه النقاط قبل استخدام الأوصاف</p>
                    <ul class="mt-1.5 space-y-1 leading-relaxed list-disc ps-4">
                        @foreach (collect($issues)->unique(fn ($i) => $i['field'].$i['message']) as $issue)
                            <li><span class="font-medium">{{ $fieldLabels[$issue['field']] ?? $issue['field'] }}:</span> {{ $issue['message'] }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs opacity-90">
                        فحصنا النص تلقائياً{{ ($profile->quality['attempts'] ?? 1) > 1 ? ' وطلبنا تصحيحه مرة' : '' }}.
                        صحّح ما يلزم بالتعديل اليدوي، أو أضف المعلومة الصحيحة إلى إجاباتك وأعد التوليد.
                    </p>
                </div>
            </div>
        @endif

        {{-- ================= شعار الهوية ================= --}}
        <div class="card p-4 flex items-center gap-4">
            @if ($logo = $brand->defaultLogo)
                <img
                    src="{{ $logo->url() }}" alt="شعار {{ $brand->name }}"
                    class="w-16 h-16 rounded-xl object-contain bg-white border border-line p-1.5 shrink-0"
                >
            @else
                <div class="w-16 h-16 rounded-xl bg-muted border border-dashed border-line-strong grid place-items-center text-fg-subtle shrink-0">
                    <x-icon name="image" class="w-6 h-6" />
                </div>
            @endif

            <div class="min-w-0">
                <h2 class="text-[15px] font-bold text-fg">شعار الهوية</h2>
                <p class="text-xs text-fg-subtle mt-0.5">
                    {{ $logo ? 'الشعار الحالي لعلامتك التجارية' : 'لم ترفع شعاراً بعد — يُركَّب على تصاميمك تلقائياً حين ترفعه.' }}
                </p>
                <a href="{{ route('brand.identity') }}" class="inline-flex items-center gap-1.5 mt-1.5 text-xs font-semibold text-brand-700 dark:text-brand-300 hover:underline">
                    <x-icon name="pencil" class="w-3.5 h-3.5" />
                    <span>{{ $logo ? 'إدارة الشعارات' : 'رفع شعار' }}</span>
                </a>
            </div>
        </div>

        {{-- ================= الوصفان المبسط والتفصيلي ================= --}}
        @foreach ([
            'simple' => ['الوصف المبسط', 'star', 'للبايو والكابشن القصير وأي تعريف سريع بالمشروع.', 4, 800],
            'detailed' => ['الوصف التفصيلي', 'book-open', 'لصفحة «من نحن» ووصف المتجر والمحتوى الطويل.', 8, 4000],
        ] as $field => [$title, $icon, $hint, $rows, $max])
            <x-section :icon="$icon" :title="$title" x-data="{ editing: {{ $errors->has($field) ? 'true' : 'false' }} }">
                <x-slot:actions>
                    <button
                        type="button" x-show="!editing" @click="editing = true"
                        class="btn-ghost btn-sm btn-icon" title="تعديل {{ $title }}"
                    >
                        <x-icon name="pencil" class="w-4 h-4" />
                        <span class="sr-only">تعديل {{ $title }}</span>
                    </button>
                </x-slot:actions>

                <p x-show="!editing" class="text-sm text-fg leading-loose whitespace-pre-line">{{ $profile->{$field} }}</p>

                <form x-show="editing" x-cloak method="POST" action="{{ route('brand.profile.update') }}" class="space-y-2.5">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="field" value="{{ $field }}">

                    <label for="edit-{{ $field }}" class="sr-only">{{ $title }}</label>
                    <textarea
                        id="edit-{{ $field }}" name="{{ $field }}" rows="{{ $rows }}" maxlength="{{ $max }}" required
                        class="field @error($field) field-invalid @enderror"
                    >{{ old($field, $profile->{$field}) }}</textarea>

                    @error($field)
                        <p class="error-text">{{ $message }}</p>
                    @else
                        <p class="hint">{{ $hint }} يُحفظ كنسخة يدوية، وتبقى نسخة الذكاء في السجل.</p>
                    @enderror

                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary btn-sm"><x-icon name="check" class="w-4 h-4" /><span>حفظ</span></button>
                        <button type="button" @click="editing = false" class="btn-ghost btn-sm"><span>إلغاء</span></button>
                    </div>
                </form>
            </x-section>
        @endforeach

        {{-- ================= الوصف التقني ================= --}}
        <x-section
            icon="code" title="الوصف التقني"
            description="ما يقرؤه كاتب المحتوى الآلي قبل كل منشور — لا يُنشر للعملاء."
            x-data="{ open: true, editing: {{ $errors->has('technical.*') ? 'true' : 'false' }} }"
        >
            <x-slot:actions>
                <button type="button" x-show="open && !editing" @click="editing = true" class="btn-ghost btn-sm btn-icon" title="تعديل الوصف التقني">
                    <x-icon name="pencil" class="w-4 h-4" />
                    <span class="sr-only">تعديل الوصف التقني</span>
                </button>
                <button
                    type="button" @click="open = !open" class="btn-ghost btn-sm btn-icon"
                    :aria-expanded="open" aria-controls="technical-body"
                >
                    <x-icon name="chevron-up" class="w-4 h-4 transition-transform" ::class="!open && 'rotate-180'" />
                    <span class="sr-only" x-text="open ? 'طي' : 'توسيع'">طي</span>
                </button>
            </x-slot:actions>

            <div id="technical-body" x-show="open" x-transition.opacity.duration.150ms>
                @if ($kept)
                    <p class="hint mt-0 mb-3 flex items-start gap-1.5">
                        <x-icon name="check-circle" class="w-3.5 h-3.5 mt-px shrink-0 text-success" />
                        <span>بقي كما في النسخة السابقة لأن إجاباته لم تتغير: {{ implode('، ', $kept) }}.</span>
                    </p>
                @endif

                <dl x-show="!editing" class="divide-y divide-line">
                    @foreach (BrandProfile::TECHNICAL_LABELS as $key => $label)
                        {{-- الملاحظات قبل الرابط، بنفس ترتيب ما يقرؤه النموذج --}}
                        @if ($key === 'store_url')
                            <div class="py-3">
                                <dt class="text-sm font-semibold text-fg">ملاحظات إضافية مهمة</dt>
                                <dd class="mt-1.5">
                                    <ol class="list-decimal ps-5 space-y-1 text-sm text-fg-muted leading-relaxed marker:text-fg-subtle marker:tnum">
                                        @foreach (\App\Services\Brand\BrandProfileGenerator::FIXED_NOTES as $note)
                                            <li>{{ $note }} <span class="chip-quiet !py-0 text-[10px] align-middle">ثابتة</span></li>
                                        @endforeach
                                        @foreach ($notes as $note)
                                            <li>{{ $note }}</li>
                                        @endforeach
                                        @foreach ($rules as $rule)
                                            <li>{{ $rule }} <span class="chip-brand !py-0 text-[10px] align-middle">من قواعدك</span></li>
                                        @endforeach
                                    </ol>
                                    <p class="hint">
                                        «ثابتة» يكتبها النظام في كل نسخة. «من قواعدك» تُعدَّل من إعدادات الكتابة ولا تمسّها إعادة التوليد.
                                    </p>
                                </dd>
                            </div>
                        @endif

                        <div class="py-3 first:pt-0 last:pb-0">
                            <dt class="text-sm font-semibold text-fg">{{ $label }}</dt>
                            <dd class="mt-1 text-sm text-fg-muted leading-relaxed">
                                @php $v = trim((string) ($technical[$key] ?? '')); @endphp

                                @if ($v === '')
                                    <span class="text-fg-subtle">—</span>
                                @elseif ($key === 'store_url')
                                    <a href="{{ $v }}" target="_blank" rel="noopener" dir="ltr" class="text-brand-700 dark:text-brand-300 hover:underline break-all">{{ $v }}</a>
                                @else
                                    {{ $v }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <form x-show="editing" x-cloak method="POST" action="{{ route('brand.profile.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="field" value="technical">

                    <p class="alert-info !py-2.5 text-xs">
                        <x-icon name="info" class="w-4 h-4 shrink-0" />
                        <span>نوع المشروع واسمه ورابطه حقائق من إجاباتك — عدّلها من «تعديل الإجابات».</span>
                    </p>

                    @foreach ([
                        'activity_type' => ['نوع النشاط', 1, 300],
                        'sales_summary' => ['ملخص المبيعات', 3, 1500],
                        'advantages_directives' => ['توجيهات المزايا التنافسية', 3, 1500],
                    ] as $key => [$label, $rows, $max])
                        <x-field :label="$label" :name="'technical.'.$key" :for="'tech-'.$key">
                            <textarea
                                id="tech-{{ $key }}" name="technical[{{ $key }}]" rows="{{ $rows }}" maxlength="{{ $max }}"
                                class="field @error('technical.'.$key) field-invalid @enderror"
                            >{{ old('technical.'.$key, $technical[$key] ?? '') }}</textarea>
                        </x-field>
                    @endforeach

                    <div>
                        <label for="tech-notes" class="label">ملاحظات إضافية مهمة</label>
                        <textarea
                            id="tech-notes" name="technical[important_notes]" rows="5" maxlength="2000"
                            class="field @error('technical.important_notes') field-invalid @enderror"
                        >{{ old('technical.important_notes', implode("\n", $notes)) }}</textarea>

                        @error('technical.important_notes')
                            <ul class="error-text flex-col items-stretch gap-1">
                                @foreach ($errors->get('technical.important_notes') as $message)
                                    <li class="flex items-start gap-1.5"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px shrink-0" /><span>{{ $message }}</span></li>
                                @endforeach
                            </ul>
                        @else
                            <p class="hint">
                                تعليمة في كل سطر. القيود الثلاثة «الثابتة» لا تظهر هنا لأنها لا تُحذف.
                                وما تضيفه أنت يُنقل إلى «قواعدك» ليبقى بعد إعادة التوليد.
                            </p>
                        @enderror
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary btn-sm"><x-icon name="check" class="w-4 h-4" /><span>حفظ</span></button>
                        <button type="button" @click="editing = false" class="btn-ghost btn-sm"><span>إلغاء</span></button>
                    </div>
                </form>
            </div>
        </x-section>

        {{-- ================= الأسئلة والإجابات ================= --}}
        <x-section
            id="answers-card" icon="help" title="الأسئلة والإجابات"
            description="مصدر كل ما سبق — والمكان الوحيد لتعديل بيانات مشروعك."
            x-data="{ open: true }"
        >
            <x-slot:actions>
                <button type="button" x-show="!answersOpen" @click="answersOpen = true; open = true" class="btn-ghost btn-sm btn-icon" title="تعديل الإجابات">
                    <x-icon name="pencil" class="w-4 h-4" />
                    <span class="sr-only">تعديل الإجابات</span>
                </button>
                <button type="button" x-show="!answersOpen" @click="open = !open" class="btn-ghost btn-sm btn-icon" :aria-expanded="open" aria-controls="answers-body">
                    <x-icon name="chevron-up" class="w-4 h-4 transition-transform" ::class="!open && 'rotate-180'" />
                    <span class="sr-only" x-text="open ? 'طي' : 'توسيع'">طي</span>
                </button>
            </x-slot:actions>

            <dl id="answers-body" x-show="open && !answersOpen" x-transition.opacity.duration.150ms class="divide-y divide-line">
                @foreach ($sheet as $row)
                    <div class="py-3 first:pt-0 last:pb-0">
                        <dt class="text-sm font-semibold text-fg">{{ $row['question'] }}</dt>
                        <dd
                            class="mt-1 text-sm leading-relaxed whitespace-pre-line {{ $row['answered'] ? 'text-fg-muted' : 'text-fg-subtle italic' }}"
                            @if ($row['key'] === 'store_url' && $row['answered']) dir="ltr" @endif
                        >{{ $row['answer'] }}</dd>
                    </div>
                @endforeach
            </dl>

            <div x-show="answersOpen" x-cloak>
                @include('brands.partials.answers-form', ['first' => false])
            </div>
        </x-section>

        @include('brands.partials.writing-settings')

        {{-- ================= الإجراءات ================= --}}
        <div class="flex flex-wrap items-center justify-center gap-2.5 pt-2">
            <button
                type="button" x-show="!answersOpen"
                @click="answersOpen = true; $nextTick(() => document.getElementById('answers-card').scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                class="btn-secondary"
            >
                <x-icon name="pencil" class="w-4 h-4" />
                <span>تعديل الإجابات</span>
            </button>

            {{--
                إجابات لم تتغير = صياغة جديدة لنفس المعنى، والتقني يبقى كما هو (تدقيق المنافس H2).
                نقولها قبل الخصم، ونترك القرار للتاجر.
            --}}
            <form
                method="POST" action="{{ route('brand.profile.regenerate') }}" data-busy-on-submit
                x-data="{ confirming: false }" class="contents"
            >
                @csrf
                <button
                    @if ($stale || $profile->isPlaceholder()) type="submit" @else type="button" @click="confirming = true" x-show="!confirming" @endif
                    class="btn-secondary"
                >
                    <x-icon name="refresh" class="w-4 h-4" />
                    <span>إعادة توليد الأوصاف</span>
                    <span class="chip-brand !py-0.5 tnum">{{ $costLabel }}</span>
                </button>

                @unless ($stale || $profile->isPlaceholder())
                    <div x-show="confirming" x-cloak class="alert-info w-full max-w-xl items-center flex-wrap" role="status">
                        <x-icon name="info" class="w-4 h-4 shrink-0" />
                        <p class="flex-1 min-w-[12rem] text-sm leading-relaxed">
                            إجاباتك لم تتغير منذ آخر توليد: سيتغير لفظ الوصفين المبسط والتفصيلي لا معناهما،
                            ويبقى الوصف التقني كما هو.
                        </p>
                        <button type="submit" class="btn-primary btn-sm"><span>أعد الصياغة ({{ $costLabel }})</span></button>
                        <button type="button" @click="confirming = false" class="btn-ghost btn-sm"><span>تراجع</span></button>
                    </div>
                @endunless
            </form>

            {{-- الحذف الشامل يطلب كتابة الاسم: نقرة واحدة لا تكفي لمسح كل النسخ --}}
            <div x-data="{ open: {{ $errors->has('confirm_name') ? 'true' : 'false' }} }" class="contents">
                <button type="button" @click="open = true" class="btn-ghost text-fg-muted hover:text-danger-fg hover:bg-danger-soft">
                    <x-icon name="trash" class="w-4 h-4" />
                    <span>حذف والبدء من جديد</span>
                </button>

                <template x-teleport="body">
                    <div
                        x-show="open" x-cloak @keydown.escape.window="open = false"
                        class="fixed inset-0 z-[60] grid place-items-center p-4"
                        role="dialog" aria-modal="true" aria-labelledby="reset-title"
                    >
                        <div x-show="open" x-transition.opacity @click="open = false" class="absolute inset-0 bg-scrim/50 backdrop-blur-sm" aria-hidden="true"></div>

                        <form
                            x-show="open" x-transition:enter="motion-safe:animate-scale-in"
                            x-init="$watch('open', v => v && $nextTick(() => $refs.confirmName?.focus()))"
                            method="POST" action="{{ route('brand.profile.destroy') }}"
                            class="relative w-full max-w-sm card shadow-pop p-6"
                        >
                            @csrf
                            @method('DELETE')

                            <div class="mx-auto grid place-items-center w-12 h-12 rounded-full bg-danger-soft text-danger-fg mb-4">
                                <x-icon name="alert" class="w-6 h-6" />
                            </div>

                            <h2 id="reset-title" class="text-base font-bold text-fg text-center">حذف ملف المشروع بالكامل؟</h2>
                            <p class="mt-2 text-sm text-fg-muted leading-relaxed text-center">
                                ستُحذف الأوصاف والإجابات و<span class="tnum">{{ $versions->count() }}</span> نسخة نهائياً.
                                الشعارات والهوية البصرية لا تتأثر.
                            </p>

                            <label for="confirm_name" class="label mt-5">اكتب <strong class="text-fg">{{ $brand->name }}</strong> للتأكيد</label>
                            <input
                                id="confirm_name" name="confirm_name" type="text" x-ref="confirmName" autocomplete="off"
                                class="field @error('confirm_name') field-invalid @enderror"
                            >
                            @error('confirm_name') <p class="error-text">{{ $message }}</p> @enderror

                            <div class="mt-6 flex gap-2.5">
                                <button type="button" @click="open = false" class="btn-secondary flex-1">إلغاء</button>
                                <button type="submit" class="btn bg-danger text-white hover:brightness-110 flex-1"><span>حذف نهائياً</span></button>
                            </div>
                        </form>
                    </div>
                </template>
            </div>
        </div>

        {{-- ================= سجل النسخ ================= --}}
        <x-section
            id="versions" icon="history" title="سجل النسخ" flush
            :description="'كل توليد أو تعديل يضيف نسخة. نحتفظ بآخر '.config('brand.profile_versions').' نسخ.'"
        >
            <ul class="divide-y divide-line">
                @foreach ($versions as $version)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3 {{ $version->is_active ? 'bg-brand-50/60 dark:bg-brand-500/5' : '' }}">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-semibold text-fg">النسخة <span class="tnum">{{ $version->version }}</span></span>
                                <span class="chip-neutral !py-0.5">{{ $version->source->label() }}</span>
                                @if ($version->is_active)
                                    <span class="chip-success !py-0.5"><x-icon name="check" class="w-3 h-3" /> المستخدمة الآن</span>
                                @endif
                                <time class="text-xs text-fg-subtle" datetime="{{ $version->created_at->toIso8601String() }}">
                                    {{ $version->created_at->diffForHumans() }}
                                </time>
                            </p>
                            <p class="mt-1 text-xs text-fg-muted truncate">{{ $version->excerpt() }}</p>
                        </div>

                        @unless ($version->is_active)
                            <div class="flex items-center gap-1 shrink-0">
                                <form method="POST" action="{{ route('brand.profile.versions.restore', $version->version) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost btn-sm">
                                        <x-icon name="history" class="w-4 h-4" />
                                        <span>استعادة</span>
                                    </button>
                                </form>

                                <x-confirm
                                    :action="route('brand.profile.versions.destroy', $version->version)"
                                    :title="'حذف النسخة '.$version->version.'؟'"
                                    message="ستُحذف هذه النسخة من السجل نهائياً. النسخة الحالية لا تتأثر."
                                    confirm="حذف النسخة"
                                />
                            </div>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </x-section>

    @elseif (! $jobUuid)

        {{-- ================= لا ملف بعد ================= --}}
        <x-section
            icon="sparkles"
            title="عرّفنا بمشروعك"
            description="من إجاباتك نكتب وصفاً مبسطاً وآخر تفصيلياً ووصفاً تقنياً يقرؤه كاتب المحتوى قبل كل منشور. أول توليد مجاني."
        >
            @include('brands.partials.answers-form', ['first' => true])
        </x-section>

        @if ($brand)
            @include('brands.partials.writing-settings')
        @endif

    @endif
</div>
@endsection
