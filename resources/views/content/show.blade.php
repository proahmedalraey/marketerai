@extends('layouts.app')
@section('title', 'تعديل المحتوى')
@section('subtitle', $item->goalLabel().' · '.$item->platformLabel())

@section('actions')
    {{-- المسودة لم تدخل الخطة بعد: مكانها صفحة الكتابة --}}
    <a href="{{ route($item->in_plan ? 'content.plan' : 'content.generator') }}" class="btn-ghost btn-sm">
        <x-icon name="arrow-right" class="w-4 h-4" />
        <span class="hidden sm:inline">{{ $item->in_plan ? 'الخطة' : 'كتابة المحتوى' }}</span>
        <span class="sm:hidden sr-only">{{ $item->in_plan ? 'رجوع إلى الخطة' : 'رجوع إلى كتابة المحتوى' }}</span>
    </a>
@endsection

@section('content')

@php
    $captionMax = (int) config("content.platforms.{$item->platform}.caption_max", 2200);
    $recommended = config("content.platforms.{$item->platform}.recommended_length");

    $issues = $item->qualityIssues();
    $errorFields = collect($issues)->where('severity', 'error')->pluck('field')->unique()->all();

    $isCarousel = $item->format->value === 'carousel' && $item->slides();
    $brand = $item->brand;
    $slideImages = $item->slideImages();
    $cover = $slideImages[0] ?? null;
    $missing = array_values(array_diff(array_keys($item->slides()), array_keys($slideImages)));
    $tiers = config('ai.quality_tiers');
    $costs = config('credits.costs', []);

    // مهمة جارية على هذا المنشور: صور شرائح أو إعادة كتابة شريحة
    $runningJob = request('job') ? \App\Models\GenerationJob::where('uuid', request('job'))->first() : null;

    $editor = [
        'caption' => $item->caption ?? '',
        'captionMax' => $captionMax,
        'slides' => $item->slides(),
        'images' => collect($slideImages)->map(fn ($asset) => $asset->url())->all(),
        'errors' => collect($errorFields)
            ->filter(fn ($field) => str_starts_with($field, 'slide:'))
            ->map(fn ($field) => (int) substr($field, 6) - 1)
            ->values()
            ->all(),
        'roles' => config('content.slide_roles'),
        'ratio' => $cover?->meta['aspect_ratio'] ?? '4:5',
        'quality' => 'standard_1k',
        'costs' => collect($tiers)->map(fn ($tier) => $tier['credits'])->all(),
        'brand' => [
            'logo' => $brand?->defaultLogo?->url(),
            'primary' => $brand?->colorFor(\App\Enums\ColorRole::Primary),
            'accent' => $brand?->colorFor(\App\Enums\ColorRole::Accent) ?? $brand?->colorFor(\App\Enums\ColorRole::Secondary),
            'font' => $brand?->font('ar_primary'),
            'slug' => $brand?->slug,
        ],
        'urls' => ['rewrite' => route('content.slides.rewrite', [$item, '__INDEX__'])],
    ];
@endphp

<div class="grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-5 items-start" x-data="contentEditor(@js($editor))">

    {{-- ================= المحرر ================= --}}
    <form
        method="POST" action="{{ route('content.update', $item) }}"
        class="space-y-5 min-w-0"
    >
        @csrf @method('PUT')

        @if ($runningJob && ! $runningJob->status->isFinished())
            <div class="card p-4" x-data="jobTracker(@js($runningJob->uuid), { redirectTo: @js(route('content.show', $item)) })">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-fg">
                        <x-icon name="refresh" class="w-4 h-4 text-brand-600 dark:text-brand-400" />
                        {{ $runningJob->type === 'slide_text' ? 'نعيد كتابة الشريحة' : 'نولّد صور الشرائح' }}
                    </h2>
                    <span class="chip-info" x-text="label">في الانتظار</span>
                </div>
                <div class="h-2 rounded-full bg-muted overflow-hidden" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="التقدّم">
                    <div class="h-full rounded-full transition-[width] duration-500 ease-out" :class="failed ? 'bg-danger' : 'bg-brand-500'" :style="`width: ${progress}%`"></div>
                </div>
                <p class="mt-2.5 text-xs leading-relaxed" role="status" aria-live="polite">
                    <span x-show="!finished" class="text-fg-muted">تبقى في هذه الصفحة؛ نعرض النتيجة حين تجهز.</span>
                    <span x-show="finished && !failed && !timedOut" x-cloak class="text-success-fg">اكتمل. نعرضه الآن…</span>
                    <span x-show="failed" x-cloak class="text-danger-fg" x-text="error || 'تعذّر الإكمال وأُرجعت النقاط.'"></span>
                    <span x-show="timedOut" x-cloak class="text-warning-fg">يستغرق أطول من المعتاد. حدّث الصفحة بعد دقائق.</span>
                </p>
            </div>
        @endif

        <p x-show="notice" x-cloak class="alert-warning text-sm" role="status">
            <x-icon name="alert-circle" class="w-4 h-4 shrink-0 mt-px" />
            <span class="flex-1" x-text="notice"></span>
            <button type="button" @click="notice = ''" class="shrink-0 -m-1 p-1 rounded-lg"><x-icon name="close" class="w-4 h-4" /><span class="sr-only">إخفاء</span></button>
        </p>

        {{-- الوسوم التعريفية --}}
        <div class="flex flex-wrap items-center gap-2">
            <span class="{{ $item->status->chipClasses() }}">
                <x-icon :name="$item->status->icon()" class="w-3.5 h-3.5" />
                {{ $item->status->label() }}
            </span>

            <span class="chip-neutral">
                <x-icon :name="$item->format->icon()" class="w-3.5 h-3.5" />
                {{ $item->variantLabel() }}
            </span>

            <span class="chip-quiet">{{ $item->platformLabel() }}</span>

            @if ($option = $item->optionLabel())
                <span class="chip-quiet">{{ $option }}</span>
            @endif

            @if ($dialect = $item->dialectLabel())
                <span class="chip-quiet">{{ $dialect }}</span>
            @elseif ($item->language !== 'ar')
                <span class="chip-quiet">{{ $item->languageLabel() }}</span>
            @endif

            @if ($item->templateLabel())
                <span class="chip-quiet">{{ $item->templateLabel() }}</span>
            @endif

            @if ($item->product)
                <span class="chip-quiet">
                    <x-icon name="package" class="w-3.5 h-3.5" />
                    {{ $item->product->title }}
                </span>
            @endif

            @if ($angle = config('content.angles.'.($item->body['angle'] ?? '').'.label'))
                <span class="chip-quiet">زاوية: {{ $angle }}</span>
            @endif
        </div>

        {{-- بوابة الصدق: ما بقي بعد التصحيح التلقائي، ليقرر فيه التاجر قبل النشر --}}
        @if ($issues)
            <div class="{{ $item->needsReview() ? 'alert-danger' : 'alert-warning' }}" role="status">
                <x-icon name="alert-circle" class="w-5 h-5 shrink-0 mt-px" />
                <div class="flex-1 min-w-0">
                    <p class="font-semibold">
                        {{ $item->needsReview() ? 'راجع قبل النشر: في النص ما لم تذكره أنت' : 'ملاحظات على الصياغة' }}
                    </p>
                    <ul class="mt-1.5 space-y-1 leading-relaxed list-disc ps-4">
                        @foreach ($issues as $issue)
                            <li>
                                <span class="font-medium">{{ \App\Services\Content\Quality\ContentQualityCheck::fieldLabel($issue['field']) }}:</span>
                                {{ $issue['message'] }}
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs opacity-90">
                        @if (! empty($item->quality['edited']))
                            أعدنا فحص النص بعد آخر تعديل لك.
                        @else
                            فحصنا النص تلقائياً{{ ($item->quality['attempts'] ?? 1) > 1 ? ' وطلبنا تصحيحه مرة دون أن نخصم نقاطاً' : '' }}.
                        @endif
                        احذف ما لم يرد في بيانات منتجك أو صحّحه، أو أضف المعلومة الصحيحة إلى المنتج ثم ولّد من جديد.
                    </p>
                </div>
            </div>
        @endif

        {{-- التدقيق اللغوي: ما صُحّح يُعرض، فلا يتفاجأ التاجر بكلمة غير التي توقعها --}}
        @if (($proof = $item->quality['proofread'] ?? null) && ($proof['status'] ?? null) === 'applied' && ! empty($proof['changes']))
            <details class="card px-4 py-3 text-sm">
                <summary class="flex items-center gap-2 cursor-pointer text-fg-muted">
                    <x-icon name="check-circle" class="w-4 h-4 text-success shrink-0" />
                    <span>دققنا النص لغوياً وصححنا {{ count($proof['changes']) === 1 ? 'خطأً واحداً' : count($proof['changes']).' أخطاء' }}</span>
                </summary>
                <ul class="mt-2.5 space-y-1.5 text-fg-muted">
                    @foreach ($proof['changes'] as $change)
                        <li class="flex flex-wrap items-baseline gap-x-2">
                            <span class="text-xs text-fg-subtle">{{ \App\Services\Content\Quality\ContentQualityCheck::fieldLabel($change['field']) }}</span>
                            <del class="text-danger-fg">{{ $change['before'] ?: '—' }}</del>
                            <span aria-hidden="true">←</span>
                            <ins class="no-underline font-medium text-fg">{{ $change['after'] ?: '(حُذفت)' }}</ins>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        @if ($isCarousel)
            <x-section
                title="شرائح الكاروسيل"
                icon="layers"
                description="عدّل النص وترى الشريحة فوراً. التعديل والترتيب مجانيان، ويُحفظان بزر «حفظ التعديلات»."
            >
                <x-slot:actions>
                    <button type="button" @click="copySlides()" class="btn-ghost btn-sm">
                        <x-icon name="copy" class="w-4 h-4" />
                        <span x-text="copied ? 'نُسخت' : 'نسخ الشرائح'">نسخ الشرائح</span>
                    </button>
                </x-slot:actions>

                <ol class="space-y-3">
                    <template x-for="(slide, i) in slides" :key="slide.key">
                        <li
                            class="rounded-xl border p-3 grid grid-cols-[6.5rem_minmax(0,1fr)] sm:grid-cols-[9.5rem_minmax(0,1fr)] gap-3"
                            :class="hasError(slide) ? 'border-danger' : 'border-line'"
                        >
                            {{-- المعاينة: ما سيُنشر بالضبط، بخط العلامة وألوانها --}}
                            <div class="min-w-0">
                                <canvas
                                    :data-slide-key="slide.key"
                                    class="w-full rounded-lg bg-muted"
                                    :style="`aspect-ratio: ${ratioCss}`"
                                    role="img" :aria-label="`معاينة الشريحة ${i + 1}`"
                                ></canvas>
                                <button type="button" @click="download(i)" class="btn-ghost btn-sm w-full mt-1.5">
                                    <x-icon name="download" class="w-3.5 h-3.5" />
                                    <span>تنزيل</span>
                                </button>
                            </div>

                            <div class="min-w-0 space-y-2">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="grid place-items-center w-6 h-6 rounded-md bg-brand-600 text-white text-[11px] font-bold tnum" x-text="i + 1"></span>

                                    <label class="sr-only" :for="`role-${slide.key}`">دور الشريحة</label>
                                    <select :id="`role-${slide.key}`" :name="`slides[${i}][role]`" x-model="slide.role" class="field w-auto py-1 ps-2.5 pe-8 text-sm">
                                        @foreach (config('content.slide_roles') as $key => $role)
                                            <option value="{{ $key }}">{{ $role['label'] }}</option>
                                        @endforeach
                                    </select>

                                    <span x-show="hasError(slide)" x-cloak class="chip-danger text-[10px] py-0.5">راجعها</span>
                                    <span x-show="slide.origin === null" x-cloak class="chip-info text-[10px] py-0.5">جديدة</span>

                                    <span class="ms-auto flex items-center">
                                        <button type="button" @click="move(i, -1)" :disabled="i === 0" class="btn-ghost btn-sm btn-icon" title="تقديم">
                                            <x-icon name="chevron-up" class="w-4 h-4" /><span class="sr-only">تقديم الشريحة</span>
                                        </button>
                                        <button type="button" @click="move(i, 1)" :disabled="i === slides.length - 1" class="btn-ghost btn-sm btn-icon" title="تأخير">
                                            <x-icon name="chevron-down" class="w-4 h-4" /><span class="sr-only">تأخير الشريحة</span>
                                        </button>
                                        <button type="button" @click="remove(i)" class="btn-ghost btn-sm btn-icon text-fg-subtle hover:text-danger-fg hover:bg-danger-soft" title="حذف">
                                            <x-icon name="trash" class="w-4 h-4" /><span class="sr-only">حذف الشريحة</span>
                                        </button>
                                    </span>
                                </div>

                                <input type="hidden" :name="`slides[${i}][origin]`" :value="slide.origin ?? 'new'">

                                {{-- الهوك بطبقاته الثلاث كما تُرسم؛ غيره نص واحد --}}
                                <template x-if="hasParts(slide)">
                                    <div class="space-y-1.5">
                                        <input type="hidden" :name="`slides[${i}][text]`" :value="joined(slide)">
                                        <input type="text" :name="`slides[${i}][kicker]`" x-model="slide.kicker" maxlength="160" class="field py-1.5 text-sm" placeholder="التمهيد (صغير)" aria-label="تمهيد الهوك">
                                        <input type="text" :name="`slides[${i}][focal]`" x-model="slide.focal" maxlength="160" class="field py-1.5 font-bold" placeholder="العبارة المحورية (كبيرة)" aria-label="العبارة المحورية">
                                        <input type="text" :name="`slides[${i}][tail]`" x-model="slide.tail" maxlength="300" class="field py-1.5 text-sm" placeholder="التكملة" aria-label="تكملة الهوك">
                                    </div>
                                </template>
                                <template x-if="! hasParts(slide)">
                                    <textarea
                                        :name="`slides[${i}][text]`" x-model="slide.text" :data-slide-text="slide.key"
                                        rows="2" maxlength="600" class="field" :class="hasError(slide) && 'field-invalid'"
                                        :aria-label="`نص الشريحة ${i + 1}`"
                                    ></textarea>
                                </template>

                                <p class="hint mt-0" x-text="roleDirective(slide.role)"></p>

                                {{-- ما يحتاج النموذج: على النص المحفوظ، ولشريحة محفوظة --}}
                                <div x-show="slide.origin !== null" class="flex flex-wrap items-center gap-1.5 pt-1" x-data="{ open: false, note: '' }">
                                    <button type="button" @click="open = ! open" :aria-expanded="open" class="btn-ghost btn-sm">
                                        <x-icon name="sparkles" class="w-3.5 h-3.5" />
                                        <span>أعد الكتابة · {{ $costs['content.slide_regen'] ?? 1 }} نقطة</span>
                                    </button>
                                    <button type="button" @click="regenerateImage(i)" class="btn-ghost btn-sm">
                                        <x-icon name="image" class="w-3.5 h-3.5" />
                                        <span x-text="`صورة جديدة · ${imageCost} نقطة`"></span>
                                    </button>

                                    <div x-show="open" x-cloak class="w-full flex flex-wrap items-center gap-1.5 rounded-lg bg-muted/60 p-2">
                                        <button type="button" @click="rewrite(i, '', note)" class="btn-secondary btn-sm"><span>صياغة أخرى</span></button>
                                        @foreach (config('content.slide_directions') as $key => $direction)
                                            <button type="button" @click="rewrite(i, @js($key), note)" class="btn-secondary btn-sm"><span>{{ $direction['label'] }}</span></button>
                                        @endforeach
                                        <input type="text" x-model="note" maxlength="200" class="field py-1.5 text-sm flex-1 min-w-[10rem]" placeholder="أو اكتب ما تريد: «اذكر الطحن عند الطلب»" aria-label="ملاحظتك على الشريحة">
                                    </div>
                                </div>
                            </div>
                        </li>
                    </template>
                </ol>

                <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
                    <button type="button" @click="add()" class="btn-ghost btn-sm">
                        <x-icon name="plus" class="w-4 h-4" />
                        <span>إضافة شريحة</span>
                    </button>
                    <span x-show="dirty" x-cloak class="text-xs text-warning-fg">تعديلات غير محفوظة</span>
                </div>
            </x-section>
        @endif

        @if ($item->script())
            <x-section title="سكربت الريل" icon="video" description="للقراءة والتصوير — يُحفظ كما وُلّد.">
                <textarea rows="8" class="field bg-muted" readonly>{{ $item->script() }}</textarea>

                <div class="mt-3">
                    <x-copy-button :text="$item->script()" label="نسخ السكربت" />
                </div>
            </x-section>
        @endif

        @if ($item->scenes())
            @php $scenesText = collect($item->scenes())->map(fn ($scene, $i) => $item->sceneText($scene, $i))->implode("\n\n"); @endphp
            <x-section title="السكريبت" icon="video" description="للتصوير والقراءة — يُحفظ كما كُتب.">
                @if (filled($item->body['hook'] ?? null))
                    <p class="mb-3 text-sm"><span class="step-pill me-1">الافتتاحية</span> {{ $item->body['hook'] }}</p>
                @endif

                <ol class="space-y-2.5">
                    @foreach ($item->scenes() as $i => $scene)
                        <li class="rounded-xl border border-line p-3">
                            <div class="flex items-center gap-2">
                                <span class="step-pill">المشهد {{ $i + 1 }}</span>
                                @if (filled($scene['time'] ?? null))
                                    <span class="text-[11px] text-fg-subtle tnum"><span dir="ltr">{{ $scene['time'] }}</span> ث</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-fg leading-relaxed">{{ $scene['voiceover'] }}</p>
                            @if (filled($scene['on_screen'] ?? null))
                                <p class="mt-1.5 text-xs text-fg-muted"><span class="font-semibold">نص الشاشة:</span> {{ $scene['on_screen'] }}</p>
                            @endif
                            @if (filled($scene['shot'] ?? null))
                                <p class="mt-2 flex gap-1.5 rounded-lg bg-muted px-2.5 py-2 text-xs text-fg-muted">
                                    <x-icon name="camera" class="w-3.5 h-3.5 mt-0.5 shrink-0" />
                                    <span><span class="font-semibold">التصوير:</span> {{ $scene['shot'] }}</span>
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>

                <div class="mt-3">
                    <x-copy-button :text="$scenesText" label="نسخ السكريبت" />
                </div>
            </x-section>
        @endif

        @if ($item->frames())
            <x-section title="إطارات الستوري" icon="smartphone" description="نص كل إطار وما يظهر فيه — يُحفظ كما كُتب.">
                <ol class="space-y-2.5">
                    @foreach ($item->frames() as $i => $frame)
                        <li class="rounded-xl border border-line p-3">
                            <span class="step-pill">الإطار {{ $i + 1 }}</span>
                            <p class="mt-2 text-sm text-fg leading-relaxed">{{ $frame['text'] }}</p>
                            @if (filled($frame['visual'] ?? null))
                                <p class="mt-1.5 text-xs text-fg-muted"><span class="font-semibold">المشهد:</span> {{ $frame['visual'] }}</p>
                            @endif
                            @if (filled($frame['interaction'] ?? null))
                                <p class="mt-1.5 text-xs text-fg-muted"><span class="font-semibold">ملصق تفاعلي:</span> {{ $frame['interaction'] }}</p>
                            @endif
                            @if (filled($frame['shot'] ?? null))
                                <p class="mt-2 flex gap-1.5 rounded-lg bg-muted px-2.5 py-2 text-xs text-fg-muted">
                                    <x-icon name="camera" class="w-3.5 h-3.5 mt-0.5 shrink-0" />
                                    <span><span class="font-semibold">التصوير:</span> {{ $frame['shot'] }}</span>
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>

                <div class="mt-3">
                    <x-copy-button :text="$item->copyText()" label="نسخ الإطارات" />
                </div>
            </x-section>
        @endif

        @if ($item->tweets())
            <x-section title="التغريدات" icon="thread" description="عدّل أي تغريدة. الحد 280 حرفاً لكل تغريدة، والهاشتاقات تُلحق بآخرها.">
                <ol class="space-y-3">
                    @foreach (old('tweets', $item->tweets()) as $i => $tweet)
                        <li>
                            <label for="tweet-{{ $i }}" class="step-pill mb-1.5">التغريدة {{ $i + 1 }}</label>
                            <textarea
                                id="tweet-{{ $i }}" name="tweets[]" rows="3" maxlength="600"
                                @class(['field', 'field-invalid' => in_array('tweet:'.($i + 1), $errorFields, true)])
                            >{{ $tweet }}</textarea>
                        </li>
                    @endforeach
                </ol>
            </x-section>
        @endif

        @if ($item->sections())
            <x-section title="الإنفوجرافيك" icon="list" description="العنوان والأقسام — تُحفظ كما كُتبت.">
                @if (filled($item->body['title'] ?? null))
                    <p class="text-base font-bold text-fg mb-3">{{ $item->body['title'] }}</p>
                @endif
                <ol class="grid sm:grid-cols-2 gap-2.5">
                    @foreach ($item->sections() as $section)
                        <li class="rounded-xl bg-muted/70 p-3">
                            <p class="text-sm font-semibold text-fg">{{ $section['heading'] }}</p>
                            <p class="mt-1 text-sm text-fg-muted leading-relaxed">{{ $section['text'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-section>
        @endif

        {{-- الستوري بلا كابشن، والثريد نصه تغريداته --}}
        @unless (in_array($item->format->value, ['story', 'thread'], true))
        <x-section :title="$item->format->value === 'post' ? 'نص المنشور' : 'الكابشن'" icon="pen">
            <label for="caption" class="sr-only">نص الكابشن</label>
            <textarea
                id="caption" name="caption" rows="7"
                @class(['field', 'field-invalid' => in_array('caption', $errorFields, true)])
                x-model="caption"
                :class="over && 'field-invalid'"
                aria-describedby="caption-counter"
            >{{ $item->caption }}</textarea>

            {{-- عدّاد حيّ: تجاوز حدّ المنصة يُكتشف هنا لا بعد لصق النص فيها --}}
            <div id="caption-counter" class="flex flex-wrap items-center justify-between gap-2 mt-2">
                <span class="text-xs text-fg-subtle">
                    @if ($recommended)
                        الطول الموصى به على {{ $item->platformLabel() }}: {{ $recommended }}
                    @endif
                </span>

                <span class="text-xs font-medium tnum" :class="over ? 'text-danger-fg' : 'text-fg-subtle'">
                    <span x-text="used"></span> / <span x-text="max"></span> حرف
                </span>
            </div>

            <p x-show="over" x-cloak class="error-text" role="alert">
                <x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" />
                <span>النص أطول من حدّ {{ $item->platformLabel() }} وسيُقتطع عند النشر.</span>
            </p>

            @if ($item->hashtags())
                <div class="mt-4 pt-4 border-t border-line">
                    <p class="flex items-center gap-1.5 text-xs font-medium text-fg-muted mb-2">
                        <x-icon name="hash" class="w-3.5 h-3.5" />
                        الهاشتاقات ({{ count($item->hashtags()) }})
                    </p>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($item->hashtags() as $tag)
                            <span class="chip-neutral" dir="ltr">#{{ $tag }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-section>
        @endunless

        <x-section title="الحالة والجدولة" icon="calendar">
            <div class="grid sm:grid-cols-2 gap-4">
                <x-field label="الحالة" name="status">
                    <select id="status" name="status" class="field">
                        @foreach (\App\Enums\ContentStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected($item->status === $status)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="تاريخ النشر المخطط" name="planned_for" optional>
                    <input
                        id="planned_for" name="planned_for" type="date" dir="ltr"
                        class="field tnum"
                        value="{{ $item->planned_for?->toDateString() }}"
                    >
                </x-field>
            </div>

            {{-- كُتب المنشور والعرض سارٍ؛ نشره بعد انتهائه وعدٌ لن يُوفى --}}
            @foreach ($item->offersEndingBeforePublish() as $offer)
                <div class="alert-warning mt-4 text-sm" role="status">
                    <x-icon name="calendar" class="w-4 h-4 shrink-0 mt-px" />
                    <p class="leading-relaxed">
                        العرض «{{ $offer->title }}» ينتهي في {{ $offer->ends_at->locale('ar')->translatedFormat('j F') }}،
                        وموعد النشر {{ $item->planned_for->locale('ar')->translatedFormat('j F') }}.
                        قدّم الموعد، أو احذف ذكر العرض من النص.
                    </p>
                </div>
            @endforeach
        </x-section>

        {{-- شريط الحفظ الثابت --}}
        <div class="sticky bottom-0 -mx-4 sm:-mx-6 lg:mx-0 px-4 sm:px-6 lg:px-0 py-3 pb-safe glass lg:bg-transparent border-t border-line lg:border-0 z-20">
            <div class="flex items-center gap-2.5">
                <button type="submit" class="btn-primary"><span>حفظ التعديلات</span></button>
                <a href="{{ route($item->in_plan ? 'content.plan' : 'content.generator') }}" class="btn-ghost"><span>رجوع</span></a>

                <x-confirm
                    class="ms-auto"
                    :action="route('content.destroy', $item)"
                    :icon-only="false"
                    label="حذف"
                    title="حذف هذا المحتوى؟"
                    message="سيُحذف النص وشرائحه نهائياً. الصور المولّدة تبقى في الاستوديو."
                />
            </div>
        </div>
    </form>

    {{-- ================= العمود الجانبي ================= --}}
    <aside class="space-y-4 lg:sticky lg:top-24">

        <x-section title="جاهز للنسخ" icon="copy" description="الكابشن والهاشتاقات كما ستلصقها في المنصة.">
            <textarea
                rows="8" class="field text-xs bg-muted leading-relaxed" readonly
                onclick="this.select()"
                aria-label="النص الكامل الجاهز للنسخ"
            >{{ $item->fullCaption() }}</textarea>

            <div class="mt-3">
                <x-copy-button :text="$item->fullCaption()" class="w-full" />
            </div>
        </x-section>

        @if ($isCarousel)
            {{--
                الغلاف أولاً ثم البقية بالغلاف مرجعاً (البند 4.3): لا يدفع التاجر ثمن
                سبع صور قبل أن يرى أسلوبها. النص لا يُطلب من نموذج الصور إطلاقاً.
            --}}
            <x-section title="صور الكاروسيل" icon="image">
                <form method="POST" action="{{ route('studio.carousel', $item) }}" class="space-y-4">
                    @csrf

                    @unless ($cover)
                        <x-field label="نسبة الأبعاد" for="aspect_ratio">
                            <select id="aspect_ratio" name="aspect_ratio" class="field" x-model="ratio">
                                @foreach (config('ai.aspect_ratios') as $key => $ratio)
                                    <option value="{{ $key }}">{{ $key }} · {{ $ratio['label'] }}</option>
                                @endforeach
                            </select>
                        </x-field>
                    @endunless

                    <x-field label="الجودة" for="quality">
                        <select id="quality" name="quality" class="field" x-model="quality">
                            @foreach ($tiers as $key => $tier)
                                <option value="{{ $key }}">{{ $tier['label'] }} — {{ $tier['credits'] }} نقطة للصورة</option>
                            @endforeach
                        </select>
                    </x-field>

                    @if (! $cover)
                        <button type="submit" name="stage" value="cover" class="btn-primary w-full">
                            <x-icon name="sparkles" class="w-4 h-4" />
                            <span x-text="`ولّد الغلاف أولاً · ${imageCost} نقطة`">ولّد الغلاف أولاً</span>
                        </button>
                        <p class="hint">ترى أسلوب الغلاف قبل أن تدفع لبقية الشرائح، ثم تتبعه كلها.</p>
                    @elseif ($missing)
                        <button type="submit" name="stage" value="rest" class="btn-primary w-full">
                            <x-icon name="check" class="w-4 h-4" />
                            <span x-text="`اعتمد الغلاف وأكمل {{ count($missing) }} شرائح · ${imageCost * {{ count($missing) }}} نقطة`">اعتمد الغلاف وأكمل الشرائح</span>
                        </button>
                        <button type="submit" name="stage" value="cover" class="btn-secondary w-full">
                            <x-icon name="refresh" class="w-4 h-4" />
                            <span x-text="`غلاف آخر · ${imageCost} نقطة`">غلاف آخر</span>
                        </button>
                        <p class="hint">إن فشلت شريحة تُسلَّم البقية، وتُرجَع نقاط الفاشلة وحدها.</p>
                    @else
                        <p class="text-sm text-fg-muted flex items-center gap-1.5">
                            <x-icon name="check-circle" class="w-4 h-4 text-success" />
                            لكل الشرائح صور. صورة شريحة بعينها من زر «صورة جديدة» عندها.
                        </p>
                    @endif
                </form>

                <div class="mt-4 pt-4 border-t border-line space-y-2">
                    <button type="button" @click="downloadAll()" class="btn-secondary w-full">
                        <x-icon name="download" class="w-4 h-4" />
                        <span>تنزيل كل الشرائح</span>
                    </button>
                    <p class="hint">
                        النص يُرسم فوق الصورة بخطوط علامتك وألوانها
                        @if (! $brand?->font('ar_primary')) — حدّد خطك من <a href="{{ route('brand.identity') }}" class="underline">الهوية البصرية</a> @endif.
                        تعديله مجاني ولا يحتاج صورة جديدة.
                    </p>
                </div>
            </x-section>

            {{-- نماذج خارج نموذج الحفظ: ما يحتاج النموذج يُرسل وحده، والنموذج المتداخل غير صالح --}}
            <form x-ref="rewriteForm" method="POST" action="" class="hidden" aria-hidden="true">
                @csrf
                <input type="hidden" name="direction">
                <input type="hidden" name="note">
            </form>

            <form x-ref="imageForm" method="POST" action="{{ route('studio.carousel', $item) }}" class="hidden" aria-hidden="true">
                @csrf
                <input type="hidden" name="stage">
                <input type="hidden" name="index">
                <input type="hidden" name="quality">
            </form>
        @endif

        @if ($item->mediaAssets->isNotEmpty())
            <x-section title="الصور المرتبطة" icon="grid">
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($item->mediaAssets as $asset)
                        <a
                            href="{{ $asset->url() }}" target="_blank" rel="noopener"
                            class="group relative block rounded-lg overflow-hidden border border-line"
                        >
                            <img
                                src="{{ $asset->url() }}"
                                alt="صورة الشريحة {{ $asset->slide_index !== null ? $asset->slide_index + 1 : '' }}"
                                loading="lazy" decoding="async"
                                class="aspect-square w-full object-cover transition motion-safe:group-hover:scale-105"
                            >
                            <span class="absolute inset-0 grid place-items-center bg-scrim/55 text-white opacity-0 transition group-hover:opacity-100">
                                <x-icon name="external" class="w-4 h-4" />
                            </span>
                        </a>
                    @endforeach
                </div>
            </x-section>
        @endif
    </aside>
</div>
@endsection
