{{--
    مسودة واحدة في «محتوى جاهز للنشر»: الوسوم، ثم المحتوى بأقسامه حسب شكله،
    ثم تاريخ النشر المقترح وأفعالها. المتغيرات: $item، $suggested، $costs.
--}}
@php
    $platform = config("content.platforms.{$item->platform}", []);
    $issues = $item->qualityIssues();
    $kind = $item->format->value;
    $hashtags = collect($item->hashtags())->map(fn ($tag) => '#'.ltrim($tag, '#'))->implode(' ');
    $planForm = 'plan-'.$item->id;
    $canRetry = (bool) \App\Services\Content\ContentFormats::get($item->variant);
    $retryCost = $costs[$item->format->creditOperation()] ?? 1;
    $tweetMax = (int) ($platform['caption_max'] ?? 280);

    // نص كل قسم لزر نسخه
    $slidesText = collect($item->slides())->pluck('text')->implode("\n\n");
    $scriptText = $item->scenes()
        ? collect($item->scenes())->map(fn ($scene, $i) => $item->sceneText($scene, $i))->implode("\n\n")
        : (string) $item->script();
    $tweetsText = collect($item->tweets())->implode("\n\n");
    $infographicText = collect([$item->body['title'] ?? ''])
        ->merge(collect($item->sections())->map(fn ($section) => $section['heading']."\n".$section['text']))
        ->filter()
        ->implode("\n\n");
    $captionLabel = $kind === 'post' ? 'المنشور' : 'الوصف';
@endphp

<article class="card p-4 sm:p-6">

    {{-- الوسوم والأفعال السريعة --}}
    <header class="flex flex-wrap items-start justify-between gap-3 pb-4 border-b border-line">
        <div class="flex flex-wrap items-center gap-1.5 min-w-0">
            <span class="chip-quiet">
                <x-icon :name="$platform['icon'] ?? 'globe'" class="w-3.5 h-3.5" />
                {{ $item->platformLabel() }}
            </span>
            <span class="chip-quiet">{{ $item->goalLabel() }}</span>
            <span class="chip-quiet">{{ $item->variantLabel() }}</span>
            @if ($option = $item->optionLabel())
                <span class="chip-quiet">{{ $option }}</span>
            @endif
            <span class="chip-quiet">{{ $item->dialectLabel() ?? $item->languageLabel() }}</span>
            @if ($item->product)
                <span class="chip-quiet max-w-[16rem] truncate">{{ $item->product->title }}</span>
            @endif
            @if ($item->withFilming())
                <span class="chip-quiet">
                    <x-icon name="camera" class="w-3.5 h-3.5" />
                    مع أسلوب التصوير
                </span>
            @endif
        </div>

        <div class="flex items-center gap-1 shrink-0">
            <a href="{{ route('content.show', $item) }}" class="btn-ghost btn-sm">
                <x-icon name="pencil" class="w-4 h-4" />
                <span>تعديل</span>
            </a>

            <div x-data="copyText(@js($item->copyText()))" class="contents">
                <button type="button" @click="copy()" class="btn-ghost btn-sm" :class="copied && 'text-success-fg'">
                    <x-icon name="copy" class="w-4 h-4" x-show="! copied" />
                    <x-icon name="check" class="w-4 h-4" x-show="copied" x-cloak />
                    <span x-text="copied ? 'نُسخ الكل' : 'نسخ الكل'">نسخ الكل</span>
                </button>
            </div>
        </div>
    </header>

    {{-- بوابة الصدق: ما بقي بعد التصحيح التلقائي --}}
    @if ($issues)
        <details class="{{ $item->needsReview() ? 'alert-danger' : 'alert-warning' }} mt-4 block" @if ($item->needsReview()) open @endif>
            <summary class="flex items-center gap-2 cursor-pointer font-semibold">
                <x-icon name="alert-circle" class="w-4 h-4 shrink-0" />
                {{ $item->needsReview() ? 'راجع قبل النشر: في النص ما لم تذكره أنت' : 'ملاحظات على الصياغة' }}
                <span class="font-normal tnum">({{ count($issues) }})</span>
            </summary>
            <ul class="mt-2 space-y-1 leading-relaxed list-disc ps-6 text-sm">
                @foreach ($issues as $issue)
                    <li>
                        <span class="font-medium">{{ \App\Services\Content\Quality\ContentQualityCheck::fieldLabel($issue['field']) }}:</span>
                        {{ $issue['message'] }}
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    {{-- ================= المحتوى حسب شكله ================= --}}
    @if ($kind === 'carousel' && $item->slides())
        <section class="py-4 border-b border-line">
            <div class="flex items-center gap-1.5 mb-3">
                <h3 class="text-sm font-bold text-fg">الشرائح</h3>
                <x-copy-icon :text="$slidesText" label="الشرائح" />
            </div>

            <ol class="divide-y divide-line">
                @foreach ($item->slides() as $i => $slide)
                    <li class="py-3 first:pt-0 last:pb-0">
                        <span class="step-pill">الشريحة {{ $i + 1 }}</span>
                        <p class="mt-2 text-sm text-fg leading-relaxed whitespace-pre-line">{{ $slide['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @if ($item->scenes() || $item->script())
        <section class="py-4 border-b border-line">
            <div class="flex items-center gap-1.5 mb-3">
                <h3 class="text-sm font-bold text-fg">السكريبت</h3>
                <x-copy-icon :text="$scriptText" label="السكريبت" />
            </div>

            @if (filled($hook = $item->body['hook'] ?? null) && ! str_contains($item->scenes()[0]['voiceover'] ?? '', $hook))
                <p class="mb-3 text-sm text-fg leading-relaxed">
                    <span class="step-pill me-1">الافتتاحية</span>
                    {{ $hook }}
                </p>
            @endif

            @if ($item->scenes())
                <ol class="divide-y divide-line">
                    @foreach ($item->scenes() as $i => $scene)
                        <li class="py-3 first:pt-0 last:pb-0">
                            <div class="flex items-center gap-2">
                                <span class="step-pill">المشهد {{ $i + 1 }}</span>
                                @if (filled($scene['time'] ?? null))
                                    <span class="text-[11px] text-fg-subtle tnum"><span dir="ltr">{{ $scene['time'] }}</span> ث</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-fg leading-relaxed">{{ $scene['voiceover'] }}</p>
                            @if (filled($scene['on_screen'] ?? null))
                                <p class="mt-1.5 text-xs text-fg-muted leading-relaxed"><span class="font-semibold">نص الشاشة:</span> {{ $scene['on_screen'] }}</p>
                            @endif
                            @if (filled($scene['shot'] ?? null))
                                <p class="mt-2 flex gap-1.5 rounded-lg bg-muted px-2.5 py-2 text-xs text-fg-muted leading-relaxed">
                                    <x-icon name="camera" class="w-3.5 h-3.5 mt-0.5 shrink-0" />
                                    <span><span class="font-semibold">التصوير:</span> {{ $scene['shot'] }}</span>
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="text-sm text-fg leading-relaxed whitespace-pre-line">{{ $item->script() }}</p>
            @endif
        </section>
    @endif

    @if ($item->frames())
        <section class="py-4 border-b border-line">
            <div class="flex items-center gap-1.5 mb-3">
                <h3 class="text-sm font-bold text-fg">الإطارات</h3>
                <x-copy-icon :text="$item->copyText()" label="الإطارات" />
            </div>

            <ol class="divide-y divide-line">
                @foreach ($item->frames() as $i => $frame)
                    <li class="py-3 first:pt-0 last:pb-0">
                        <span class="step-pill">الإطار {{ $i + 1 }}</span>
                        <p class="mt-2 text-sm text-fg leading-relaxed">{{ $frame['text'] }}</p>
                        @if (filled($frame['visual'] ?? null))
                            <p class="mt-1.5 text-xs text-fg-muted leading-relaxed"><span class="font-semibold">المشهد:</span> {{ $frame['visual'] }}</p>
                        @endif
                        @if (filled($frame['interaction'] ?? null))
                            <p class="mt-1.5 text-xs text-fg-muted leading-relaxed"><span class="font-semibold">ملصق تفاعلي:</span> {{ $frame['interaction'] }}</p>
                        @endif
                        @if (filled($frame['shot'] ?? null))
                            <p class="mt-2 flex gap-1.5 rounded-lg bg-muted px-2.5 py-2 text-xs text-fg-muted leading-relaxed">
                                <x-icon name="camera" class="w-3.5 h-3.5 mt-0.5 shrink-0" />
                                <span><span class="font-semibold">التصوير:</span> {{ $frame['shot'] }}</span>
                            </p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @if ($item->tweets())
        <section class="py-4 border-b border-line">
            <div class="flex items-center gap-1.5 mb-3">
                <h3 class="text-sm font-bold text-fg">التغريدات</h3>
                <x-copy-icon :text="$tweetsText" label="التغريدات" />
            </div>

            <ol class="divide-y divide-line">
                @foreach ($item->tweets() as $i => $tweet)
                    @php $length = mb_strlen($tweet); @endphp
                    <li class="py-3 first:pt-0 last:pb-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="step-pill">التغريدة {{ $i + 1 }}</span>
                            <span class="text-[11px] tnum {{ $length > $tweetMax ? 'text-danger-fg font-semibold' : 'text-fg-subtle' }}">{{ $length }} / {{ $tweetMax }}</span>
                        </div>
                        <p class="mt-2 text-sm text-fg leading-relaxed whitespace-pre-line">{{ $tweet }}</p>
                    </li>
                @endforeach
            </ol>

            @if ($hashtags !== '')
                <p class="mt-3 text-sm text-brand-700 dark:text-brand-400">{{ $hashtags }}</p>
            @endif
        </section>
    @endif

    @if ($item->sections())
        <section class="py-4 border-b border-line">
            <div class="flex items-center gap-1.5 mb-3">
                <h3 class="text-sm font-bold text-fg">الإنفوجرافيك</h3>
                <x-copy-icon :text="$infographicText" label="الإنفوجرافيك" />
            </div>

            @if (filled($item->body['title'] ?? null))
                <p class="text-base font-bold text-fg mb-3">{{ $item->body['title'] }}</p>
            @endif

            <ol class="grid sm:grid-cols-2 gap-2.5">
                @foreach ($item->sections() as $i => $section)
                    <li class="rounded-xl bg-muted/70 p-3">
                        <span class="step-pill">{{ $i + 1 }}</span>
                        <p class="mt-1.5 text-sm font-semibold text-fg">{{ $section['heading'] }}</p>
                        <p class="mt-1 text-sm text-fg-muted leading-relaxed">{{ $section['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- الوصف: نص المنشور نفسه، أو الكابشن الذي يرافق الشرائح والفيديو --}}
    @if (filled($item->caption))
        <section class="py-4 border-b border-line">
            <div class="flex items-center gap-1.5 mb-3">
                <h3 class="text-sm font-bold text-fg">{{ $captionLabel }}</h3>
                <x-copy-icon :text="$item->fullCaption()" :label="$captionLabel" />
            </div>

            <p class="text-sm text-fg leading-relaxed whitespace-pre-line">{{ $item->caption }}</p>

            @if ($hashtags !== '')
                <p class="mt-3 text-sm text-brand-700 dark:text-brand-400">{{ $hashtags }}</p>
            @endif
        </section>
    @endif

    {{-- ================= التاريخ والأفعال ================= --}}
    <div class="flex flex-wrap items-center gap-3 py-4 border-b border-line">
        <label for="date-{{ $item->id }}" class="flex items-center gap-2 text-sm text-fg-muted">
            <x-icon name="calendar" class="w-4 h-4 text-brand-600 dark:text-brand-400" />
            تاريخ النشر المقترح
        </label>
        <input
            id="date-{{ $item->id }}" type="date" name="planned_for" form="{{ $planForm }}"
            value="{{ $suggested }}" min="{{ today()->toDateString() }}" required dir="ltr"
            class="field w-auto min-h-10 py-1.5 tnum"
        >
    </div>

    <footer class="flex flex-wrap items-center gap-2 pt-4">
        <form id="{{ $planForm }}" method="POST" action="{{ route('content.add-to-plan', $item) }}">
            @csrf
            <button type="submit" class="btn-primary">
                <x-icon name="calendar-plus" class="w-4 h-4" />
                <span>إضافة للخطة الشهرية</span>
            </button>
        </form>

        @if ($canRetry)
            <form method="POST" action="{{ route('content.retry', $item) }}">
                @csrf
                <button type="submit" class="btn-secondary" title="نسخة جديدة بالإعدادات نفسها. هذه النسخة تبقى حتى تحذفها.">
                    <x-icon name="refresh" class="w-4 h-4" />
                    <span>إعادة المحاولة</span>
                    <span class="text-xs font-normal text-fg-subtle tnum">· {{ $retryCost }} نقطة</span>
                </button>
            </form>
        @endif

        <x-confirm
            class="ms-auto !text-danger-fg"
            :action="route('content.destroy', $item)"
            :icon-only="false"
            label="حذف هذا المحتوى"
            title="حذف هذا المحتوى؟"
            message="سيُحذف نهائياً. نقاطه لا تُرجع لأنه كُتب بنجاح."
        />
    </footer>
</article>
