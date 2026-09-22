{{--
    مودال «منشور جديد»: جدولة داخلية فقط. لا اتصال حقيقي بحسابات Instagram/Facebook/TikTok
    بعد، فبطاقات المنصات عادية بلا ادّعاء اتصال، والنشر الفوري/المجدول تذكير محلي يُعلن عن نفسه صراحة.
--}}
<div
    x-show="scheduleOpen"
    x-cloak
    class="fixed inset-0 z-[60] overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="schedule-modal-title"
>
    <div
        x-show="scheduleOpen"
        x-transition.opacity.duration.150ms
        @click="closeSchedule()"
        class="fixed inset-0 bg-scrim/50 backdrop-blur-sm"
        aria-hidden="true"
    ></div>

    <div class="relative min-h-full grid place-items-center p-4">
        <template x-if="scheduleOpen">
            <form
                method="POST" action="{{ route('scheduled.store') }}"
                x-init="$nextTick(() => requestAnimationFrame(() => $refs.firstField?.focus()))"
                @keydown.tab="
                    const f = [...$el.querySelectorAll('input:not([type=hidden]), textarea, select, button')].filter(el => el.offsetParent !== null);
                    if (! f.length) return;
                    const first = f[0], last = f[f.length - 1];
                    if ($event.shiftKey && document.activeElement === first) { $event.preventDefault(); last.focus(); }
                    else if (! $event.shiftKey && document.activeElement === last) { $event.preventDefault(); first.focus(); }
                "
                class="relative w-full max-w-2xl card shadow-pop motion-safe:animate-scale-in max-h-[90vh] flex flex-col"
            >
                @csrf
                <input type="hidden" name="source" x-model="source">
                <input type="hidden" name="mode" x-model="mode">

                <header class="sticky top-0 z-10 flex items-start justify-between gap-4 px-5 py-4 glass border-b border-line rounded-t-2xl shrink-0">
                    <div>
                        <h2 id="schedule-modal-title" class="text-base font-bold text-fg">إنشاء منشور</h2>
                        <p class="text-xs text-fg-subtle mt-0.5">اختر مصدر النص والمنصات وموعد النشر</p>
                    </div>
                    <button type="button" @click="closeSchedule()" class="btn btn-ghost btn-sm btn-icon">
                        <x-icon name="close" class="w-4 h-4" />
                        <span class="sr-only">إغلاق</span>
                    </button>
                </header>

                <div class="p-5 space-y-5 overflow-y-auto">

                    {{-- ---------- مصدر النص ---------- --}}
                    <div class="flex items-center gap-1 p-1 rounded-xl bg-muted w-fit" role="group" aria-label="مصدر النص">
                        <button type="button" @click="source = 'existing'" :class="source === 'existing' ? 'bg-card text-fg shadow-xs' : 'text-fg-subtle'" class="px-3 min-h-9 rounded-lg text-xs font-medium transition">من خطة المحتوى</button>
                        <button type="button" @click="source = 'new'" :class="source === 'new' ? 'bg-card text-fg shadow-xs' : 'text-fg-subtle'" class="px-3 min-h-9 rounded-lg text-xs font-medium transition">نص جديد</button>
                    </div>

                    <div x-show="source === 'existing'" x-cloak>
                        <x-field label="اختر من المحتوى المكتوب" name="content_item_id">
                            <select name="content_item_id" x-model="contentItemId" @change="chooseExisting($event.target.value)" class="field @error('content_item_id') field-invalid @enderror">
                                <option value="">اختر...</option>
                                <template x-for="d in drafts" :key="d.id">
                                    <option :value="d.id" x-text="d.label"></option>
                                </template>
                            </select>
                        </x-field>
                        <p class="hint" x-show="! drafts.length">لا توجد مسودات جاهزة بعد — اكتبها من «كتابة المحتوى» أولاً، أو اختر «نص جديد».</p>
                    </div>

                    <div x-show="source === 'new'" x-cloak>
                        <x-field label="النص" name="caption">
                            <textarea name="caption" x-model="caption" x-ref="firstField" rows="4" class="field @error('caption') field-invalid @enderror" placeholder="اكتب نص المنشور هنا..."></textarea>
                        </x-field>
                    </div>

                    {{-- ---------- المنصات ---------- --}}
                    <div>
                        <span class="label">المنصات</span>
                        <div class="grid grid-cols-4 sm:grid-cols-8 gap-2">
                            @foreach ($platforms as $key => $platform)
                                <label class="platform-tile">
                                    <input type="checkbox" name="platforms[]" value="{{ $key }}" class="sr-only" x-model="selectedPlatforms" @change="togglePlatform()">
                                    <x-icon :name="$platform['icon']" />
                                    <span class="text-center leading-tight">{{ $platform['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('platforms')
                            <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                        @enderror
                    </div>

                    {{-- ---------- نص مخصص لكل منصة ---------- --}}
                    <div x-show="selectedPlatforms.length > 1" x-cloak>
                        <div class="flex items-center gap-1 border-b border-line mb-3 overflow-x-auto">
                            @foreach ($platforms as $key => $platform)
                                <button
                                    type="button" x-show="selectedPlatforms.includes('{{ $key }}')" @click="activeTab = '{{ $key }}'"
                                    :class="activeTab === '{{ $key }}' ? 'border-fg text-fg' : 'border-transparent text-fg-subtle'"
                                    class="px-3 py-2 text-xs font-medium border-b-2 whitespace-nowrap transition"
                                >{{ $platform['label'] }}</button>
                            @endforeach
                        </div>

                        @foreach ($platforms as $key => $platform)
                            <div x-show="activeTab === '{{ $key }}'" x-cloak>
                                <x-field label="نص مخصص ({{ $platform['label'] }})" optional hint="اتركه فارغاً لاستخدام النص المشترك أعلاه.">
                                    <textarea name="overrides[{{ $key }}]" x-model="overrides.{{ $key }}" rows="3" maxlength="{{ $platform['caption_max'] }}" class="field"></textarea>
                                </x-field>
                                <p class="text-[11px] text-fg-subtle mt-1 tnum" x-text="charCount('{{ $key }}') + ' / {{ $platform['caption_max'] }}'"></p>
                            </div>
                        @endforeach
                    </div>

                    {{-- ---------- النشر ---------- --}}
                    <div>
                        <span class="label">النشر</span>
                        <div class="flex items-center gap-1 p-1 rounded-xl bg-muted w-fit">
                            <button type="button" @click="mode = 'draft'" :class="mode === 'draft' ? 'bg-card text-fg shadow-xs' : 'text-fg-subtle'" class="px-3 min-h-9 rounded-lg text-xs font-medium transition">مسودة</button>
                            <button type="button" @click="mode = 'now'" :class="mode === 'now' ? 'bg-card text-fg shadow-xs' : 'text-fg-subtle'" class="px-3 min-h-9 rounded-lg text-xs font-medium transition">فوري</button>
                            <button type="button" @click="mode = 'schedule'" :class="mode === 'schedule' ? 'bg-card text-fg shadow-xs' : 'text-fg-subtle'" class="px-3 min-h-9 rounded-lg text-xs font-medium transition">جدولة</button>
                        </div>
                    </div>

                    <div x-show="mode === 'schedule'" x-cloak class="grid grid-cols-2 gap-3">
                        <x-field label="التاريخ" name="scheduled_date">
                            <input type="date" name="scheduled_date" x-model="scheduledDate" class="field @error('scheduled_date') field-invalid @enderror" dir="ltr" min="{{ today()->toDateString() }}">
                        </x-field>
                        <x-field label="الوقت" name="scheduled_time">
                            <input type="time" name="scheduled_time" x-model="scheduledTime" class="field" dir="ltr">
                        </x-field>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <x-field label="المنطقة الزمنية">
                            <select class="field" disabled>
                                <option>(الرياض) GMT+3 حالي</option>
                            </select>
                        </x-field>

                        <x-field label="الخصوصية" name="privacy">
                            <select name="privacy" x-model="privacy" class="field">
                                <option value="public">عام (Public)</option>
                                <option value="private">خاص (Private)</option>
                            </select>
                        </x-field>
                    </div>

                    <p class="hint flex items-start gap-1.5">
                        <x-icon name="info" class="w-3.5 h-3.5 mt-0.5 shrink-0" />
                        <span>لا يوجد نشر تلقائي فعلي على المنصات بعد — الجدولة تذكير داخلي فقط، تنشره بنفسك في موعده.</span>
                    </p>

                    <div x-show="source === 'existing' && contentItemId" x-cloak>
                        <button type="button" @click="openMedia()" class="btn-secondary btn-sm">
                            <x-icon name="image" class="w-4 h-4" />
                            <span>اختر من الاستوديو</span>
                        </button>
                    </div>
                </div>

                <footer class="sticky bottom-0 z-10 flex items-center justify-end gap-2.5 px-5 py-4 glass border-t border-line rounded-b-2xl shrink-0">
                    <button type="button" @click="closeSchedule()" class="btn-secondary">إلغاء</button>
                    <button type="submit" class="btn-primary">
                        <x-icon name="calendar-plus" class="w-4 h-4" />
                        <span>جدولة المنشور</span>
                    </button>
                </footer>
            </form>
        </template>
    </div>
</div>
