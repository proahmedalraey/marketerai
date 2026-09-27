{{-- ================= 2. المحتوى ================= --}}
<section class="vo-card" aria-labelledby="vo-content-title">
    <button type="button" class="vo-head" @click="open.content = ! open.content" :aria-expanded="open.content ? 'true' : 'false'">
        <span class="vo-head-icon"><x-icon name="file-text" class="w-[18px] h-[18px]" /></span>
        <h2 id="vo-content-title" class="text-[15px] font-bold text-fg">المحتوى</h2>
        <span x-show="hasText" x-cloak class="vo-head-check"><x-icon name="check" class="w-3.5 h-3.5" /></span>
        <span class="text-xs text-fg-subtle truncate" x-show="! open.content && hasText" x-text="`${wordCount} كلمة`"></span>
        <x-icon name="chevron-up" class="ms-auto w-5 h-5 text-fg-subtle transition-transform duration-200" x-bind:class="! open.content && 'rotate-180'" />
    </button>

    <div x-show="open.content" class="border-t border-line px-4 sm:px-6 py-5 space-y-4">

        {{-- مصدر النص --}}
        <div class="vo-seg sm:max-w-sm" role="tablist" aria-label="مصدر النص">
            <button type="button" role="tab" class="vo-seg-btn" :aria-selected="source === 'plan' ? 'true' : 'false'" @click="source = 'plan'; toolError = ''">
                <x-icon name="file-text" class="w-4 h-4" />
                محتوى سابق
            </button>
            <button type="button" role="tab" class="vo-seg-btn" :aria-selected="source === 'new' ? 'true' : 'false'" @click="source = 'new'; toolError = ''">
                <x-icon name="mic" class="w-4 h-4" />
                نص جديد
            </button>
        </div>

        {{-- ---------- نص جديد ---------- --}}
        <div x-show="source === 'new'" class="space-y-2.5" role="tabpanel">
            <div class="flex flex-wrap items-center gap-2">
                <label for="vo-text" class="text-sm font-bold text-fg">اكتب النص</label>

                <div class="ms-auto flex flex-wrap items-center gap-2">
                    <button type="button" class="vo-tool" @click="runTool('enhance')" :data-busy="busy.enhance ? 'true' : 'false'"
                            title="يضيف وقفات ونبرة للإلقاء دون تغيير كلماتك">
                        <x-icon name="audio-lines" class="w-3.5 h-3.5" />
                        <span>تحسين الصوت</span>
                    </button>
                    <button type="button" class="vo-tool" @click="runTool('diacritize')" :data-busy="busy.diacritize ? 'true' : 'false'"
                            :disabled="language === 'en'" title="يضيف الحركات لضبط النطق">
                        <x-icon name="square-pen" class="w-3.5 h-3.5" />
                        <span>تشكيل</span>
                    </button>
                    <button type="button" class="vo-tool" @click="toggleDictation()" :aria-pressed="listening ? 'true' : 'false'"
                            :disabled="! dictationSupported"
                            :title="dictationSupported ? 'اكتب بصوتك: تحدّث ويُضاف كلامك للنص' : 'متصفحك لا يدعم الإملاء الصوتي (جرّب Chrome أو Edge)'">
                        <x-icon name="mic" class="w-3.5 h-3.5" />
                        <span x-text="listening ? 'إيقاف' : 'إملاء'">إملاء</span>
                    </button>
                    <span class="vo-count" :class="overLimit && 'bg-danger-soft text-danger-fg'" x-text="`${charCount}/${maxChars}`"></span>
                </div>
            </div>

            <textarea
                id="vo-text" x-model="text" @input="undo = null" rows="8"
                class="field min-h-[13rem] text-[15px] leading-loose"
                :class="(showErrors && ! hasText) || overLimit ? 'field-invalid' : ''"
                placeholder="اكتب النص الذي سيقرؤه المذيع… مثال: ليش قهوتك أحياناً تكون مرّة بزيادة؟ غالباً السبب يرجع لنوع البن."
            ></textarea>

            @include('content.voiceover._tool-feedback')
        </div>

        {{-- ---------- محتوى سابق ---------- --}}
        <div x-show="source === 'plan'" x-cloak class="space-y-3" role="tabpanel">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-bold text-fg">اختر من خطتك</h3>
                <span class="vo-count" x-text="`${filteredPlan.length}/${planItems.length}`"></span>
            </div>

            <template x-if="planItems.length === 0">
                <div class="rounded-xl border border-dashed border-line-strong px-4 py-8 text-center">
                    <x-icon name="calendar" class="w-8 h-8 mx-auto text-fg-subtle" />
                    <p class="mt-2 text-sm font-semibold text-fg">لا محتوى في خطتك بعد</p>
                    <p class="mt-1 text-xs text-fg-subtle">اكتب محتوى وأضفه للخطة الشهرية، ثم حوّله هنا لتعليق صوتي.</p>
                    <a href="{{ route('content.generator') }}" class="btn-secondary btn-sm mt-3">
                        <x-icon name="pen" class="w-4 h-4" />
                        كتابة المحتوى
                    </a>
                </div>
            </template>

            <template x-if="planItems.length > 0">
                <div class="space-y-3">
                    <div class="relative">
                        <x-icon name="search" class="absolute start-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-fg-subtle pointer-events-none" />
                        <input type="search" x-model="planSearch" class="field ps-10" placeholder="ابحث…" aria-label="ابحث في محتوى الخطة">
                    </div>

                    <div class="flex flex-wrap gap-2" role="group" aria-label="تصفية حسب المنصة">
                        <button type="button" class="vo-filter" :aria-pressed="planPlatform === 'all' ? 'true' : 'false'" @click="planPlatform = 'all'">الكل</button>
                        <template x-for="(platform, key) in planPlatforms" :key="key">
                            <button type="button" class="vo-filter" :aria-pressed="planPlatform === key ? 'true' : 'false'" @click="planPlatform = key">
                                <span x-text="platform.label"></span>
                                <span class="tnum" x-text="`(${platform.count})`"></span>
                            </button>
                        </template>
                    </div>

                    <div class="vo-scroll max-h-[26rem] -mx-1 px-1 pe-2 py-1">
                        <div role="radiogroup" aria-label="محتوى الخطة" class="grid sm:grid-cols-2 gap-2.5">
                            <template x-for="item in filteredPlan" :key="item.id">
                                <button type="button" role="radio" class="vo-plan" :aria-checked="planId === item.id ? 'true' : 'false'" @click="choosePlan(item)">
                                    <span class="flex items-start gap-2 w-full">
                                        <span class="flex flex-wrap gap-1">
                                            <span class="vo-plan-tag" x-text="item.platform_label"></span>
                                            <span class="vo-plan-tag" x-show="item.dialect_label" x-text="item.dialect_label"></span>
                                            <span class="vo-plan-tag" x-show="item.goal_label" x-text="item.goal_label"></span>
                                        </span>
                                        <span x-show="planId === item.id" class="ms-auto grid place-items-center w-5 h-5 shrink-0 rounded-full bg-fg-inverse text-fg">
                                            <x-icon name="check" stroke="3" class="w-3 h-3" />
                                        </span>
                                    </span>
                                    <span class="block text-[13px] font-bold line-clamp-1" x-text="item.title"></span>
                                    <span class="block text-xs opacity-70 line-clamp-1" x-text="item.subtitle"></span>
                                    <span class="block text-[11px] opacity-60 tnum" x-text="`${item.date} · ${item.words} كلمة`"></span>
                                </button>
                            </template>
                        </div>

                        <p x-show="filteredPlan.length === 0" class="py-8 text-center text-sm text-fg-subtle">لا نتائج لهذا البحث.</p>
                    </div>
                </div>
            </template>

            {{-- تعديل النص المختار قبل التوليد --}}
            <div x-show="planId" x-cloak x-ref="planEditor" class="rounded-2xl border border-line p-4 space-y-2.5 scroll-mt-24">
                <div class="flex items-center gap-2">
                    <x-icon name="square-pen" class="w-4 h-4 text-fg-muted" />
                    <label for="vo-plan-text" class="text-sm font-semibold text-fg-muted">تعديل النص قبل التوليد</label>

                    <div class="ms-auto flex items-center gap-1.5">
                        <button type="button" class="vo-tool" @click="runTool('enhance')" :data-busy="busy.enhance ? 'true' : 'false'"
                                title="يضيف وقفات ونبرة للإلقاء دون تغيير كلماتك">
                            <x-icon name="audio-lines" class="w-3.5 h-3.5" />
                            <span>تحسين الصوت</span>
                        </button>
                        <button type="button" @click="clearPlan()" class="btn-ghost btn-sm btn-icon" aria-label="إلغاء اختيار المحتوى">
                            <x-icon name="close" class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                <textarea
                    id="vo-plan-text" x-model="planText" @input="undo = null" rows="6"
                    class="field vo-scroll text-[15px] leading-loose bg-muted/40" :class="overLimit && 'field-invalid'"
                ></textarea>

                <div class="flex items-center justify-between text-xs text-fg-subtle">
                    <span class="tnum" x-text="`${wordCount} كلمة`"></span>
                    <span class="vo-count" :class="overLimit && 'bg-danger-soft text-danger-fg'" x-text="`${charCount}/${maxChars}`"></span>
                </div>

                @include('content.voiceover._tool-feedback')
            </div>
        </div>
    </div>
</section>
