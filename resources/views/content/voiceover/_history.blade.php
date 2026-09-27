{{-- ================= السجل السابق ================= --}}
<aside class="vo-card lg:sticky lg:top-20" aria-labelledby="vo-history-title">
    <div class="vo-head min-h-16">
        <span class="vo-head-icon"><x-icon name="history" class="w-[18px] h-[18px]" /></span>
        <h2 id="vo-history-title" class="text-[15px] font-bold text-fg">السجل السابق</h2>
        <span class="vo-count" x-text="`${history.length} ملف`"></span>
        <button
            type="button" @click="historyOpen = ! historyOpen" class="ms-auto btn-ghost btn-sm btn-icon"
            :aria-expanded="historyOpen ? 'true' : 'false'" aria-controls="vo-history-list"
            :aria-label="historyOpen ? 'طي السجل' : 'فتح السجل'"
        >
            <x-icon name="chevron-up" class="w-5 h-5 transition-transform duration-200" x-bind:class="! historyOpen && 'rotate-180'" />
        </button>
    </div>

    <div x-show="historyOpen" id="vo-history-list" class="border-t border-line">
        <div class="flex justify-end px-4 pt-3">
            <button type="button" @click="refreshHistory()" class="btn-secondary btn-sm min-h-8" :data-busy="refreshing ? 'true' : 'false'">
                <x-icon name="refresh" class="w-3.5 h-3.5" />
                <span>تحديث</span>
            </button>
        </div>

        <div class="vo-scroll max-h-[calc(100vh-11rem)] lg:max-h-[calc(100vh-13rem)] p-4 pe-3 space-y-3">

            {{-- ---------- قيد التسجيل ---------- --}}
            <template x-for="job in running" :key="job.uuid">
                <article class="vo-history-item border-dashed" aria-busy="true">
                    <header class="flex items-center gap-2">
                        <h3 class="text-sm font-bold text-fg" x-text="job.voice_name"></h3>
                        <span class="vo-count text-[11px]" x-show="job.tier" x-text="job.tier"></span>
                        <span class="text-xs text-fg-subtle truncate" x-text="job.style"></span>
                        <span class="ms-auto w-2 h-2 rounded-full bg-brand-500 animate-pulse-dot" aria-hidden="true"></span>
                    </header>
                    <p class="mt-2 text-[13px] leading-relaxed text-fg-muted line-clamp-2" x-text="job.text"></p>
                    <div class="mt-3 h-1.5 rounded-full bg-muted overflow-hidden" role="progressbar" :aria-valuenow="job.progress" aria-valuemin="0" aria-valuemax="100" aria-label="تقدّم التسجيل">
                        <div class="h-full rounded-full bg-brand-500 transition-[width] duration-500 ease-out" :style="`width: ${job.progress}%`"></div>
                    </div>
                    <p class="mt-1.5 text-[11px] text-fg-subtle" x-text="job.stage"></p>
                </article>
            </template>

            {{-- ---------- الملفات ---------- --}}
            <template x-for="item in history" :key="item.id">
                <div class="space-y-2">
                    <article class="vo-history-item" :data-active="player.id === item.id ? 'true' : 'false'">
                        <header class="flex items-center gap-2 min-w-0">
                            <h3 class="text-sm font-bold text-fg shrink-0" x-text="item.voice_name"></h3>
                            <span class="vo-count text-[11px] shrink-0" x-show="item.tier" x-text="item.tier"></span>
                            <span class="text-xs text-fg-subtle truncate" x-text="item.style"></span>
                        </header>

                        <p class="mt-2 text-[13px] leading-relaxed text-fg-muted line-clamp-3" x-text="item.text"></p>

                        <div class="mt-2.5 flex flex-wrap items-center gap-2 text-[11px] text-fg-subtle">
                            <span x-text="item.created"></span>
                            <span x-show="item.expires" class="inline-flex items-center gap-1 rounded-md bg-muted px-1.5 py-0.5">
                                <x-icon name="timer" class="w-3 h-3" />
                                <span x-text="item.expires"></span>
                            </span>
                            <span x-show="item.pinned" class="inline-flex items-center gap-1 rounded-md bg-success-soft text-success-fg px-1.5 py-0.5">
                                <x-icon name="bookmark" class="w-3 h-3" />
                                محفوظ
                            </span>
                            <span
                                class="grid place-items-center w-5 h-5 rounded-full bg-muted text-[10px] font-bold cursor-help"
                                :title="item.pinned ? 'محفوظ: لن يُحذف تلقائياً.' : `يُحذف تلقائياً بعد ${retentionDays} يوماً من إنشائه ما لم تحفظه.`"
                                aria-hidden="true"
                            >!</span>
                        </div>

                        <div class="mt-3 flex items-center gap-2.5">
                            <button
                                type="button" class="vo-play" @click="toggle(item)"
                                :aria-label="player.id === item.id && player.playing ? `إيقاف تعليق ${item.voice_name}` : `تشغيل تعليق ${item.voice_name}`"
                            >
                                <x-icon name="pause" class="w-4 h-4" x-show="player.id === item.id && player.playing" />
                                <x-icon name="play" class="w-4 h-4 ms-0.5" x-show="! (player.id === item.id && player.playing)" />
                            </button>
                            <span class="min-w-0">
                                <span class="block text-xs font-semibold text-fg truncate" x-text="item.voice_name"></span>
                                <span class="block text-[11px] text-fg-subtle tnum" x-text="`${time(item.duration)} · ${item.language}`"></span>
                            </span>

                            <div class="ms-auto flex items-center">
                                <button type="button" @click="remove(item)" class="btn-ghost btn-sm btn-icon text-fg-subtle hover:text-danger-fg hover:bg-danger-soft" aria-label="حذف">
                                    <x-icon name="trash" class="w-4 h-4" />
                                </button>
                                <button
                                    type="button" @click="pin(item)" class="btn-ghost btn-sm btn-icon"
                                    :class="item.pinned ? 'text-brand-600 dark:text-brand-400' : 'text-fg-subtle'"
                                    :aria-pressed="item.pinned ? 'true' : 'false'"
                                    :aria-label="item.pinned ? 'إلغاء الحفظ' : 'حفظ (لن يُحذف تلقائياً)'"
                                >
                                    <x-icon name="bookmark" class="w-4 h-4" x-bind:class="item.pinned && 'fill-current'" />
                                </button>
                                <a :href="item.url" :download="item.download" class="btn-ghost btn-sm btn-icon text-fg-subtle" aria-label="تنزيل">
                                    <x-icon name="download" class="w-4 h-4" />
                                </a>
                            </div>
                        </div>
                    </article>

                    {{-- ---------- المشغّل ---------- --}}
                    <div x-show="player.id === item.id" x-cloak class="vo-player">
                        <div class="flex items-center gap-2.5">
                            <button type="button" class="vo-player-btn w-9 h-9" @click="toggle(item)" :aria-label="player.playing ? 'إيقاف' : 'تشغيل'">
                                <x-icon name="pause" class="w-3.5 h-3.5" x-show="player.playing" />
                                <x-icon name="play" class="w-3.5 h-3.5 ms-0.5" x-show="! player.playing" />
                            </button>

                            <div class="vo-wave flex-1" @click="seek($event)" role="slider" tabindex="0"
                                 aria-label="موضع التشغيل" :aria-valuenow="Math.round(player.current)" aria-valuemin="0" :aria-valuemax="Math.round(player.duration)"
                                 @keydown.arrow-right.prevent="seekBy(5)" @keydown.arrow-left.prevent="seekBy(-5)">
                                <template x-for="(peak, i) in player.peaks" :key="i">
                                    <span :style="`height: ${Math.round(peak * 100)}%`" :data-on="(i + 0.5) / player.peaks.length <= progressRatio ? 'true' : 'false'"></span>
                                </template>
                            </div>
                        </div>

                        <div class="h-1 rounded-full bg-fg-inverse/20 overflow-hidden cursor-pointer" dir="ltr" @click="seek($event)">
                            <div class="h-full rounded-full bg-brand-400" :style="`width: ${progressRatio * 100}%`"></div>
                        </div>

                        <div class="flex items-center justify-between text-[11px] tnum opacity-80" dir="ltr">
                            <span x-text="time(player.current)"></span>
                            <span x-text="time(player.duration)"></span>
                        </div>

                        <div class="flex items-center gap-2">
                            <a class="vo-player-chip" :href="item.url" :download="item.download">
                                <span x-text="item.format"></span>
                                <x-icon name="download" class="w-3.5 h-3.5" />
                            </a>

                            <div class="ms-auto flex items-center gap-1.5" dir="ltr" role="group" aria-label="سرعة التشغيل">
                                <button type="button" class="vo-player-btn" @click="setRate(-0.25)" aria-label="أبطأ">
                                    <x-icon name="minus" class="w-3.5 h-3.5" />
                                </button>
                                <span class="w-11 text-center text-xs font-bold tnum" x-text="`${player.rate}x`"></span>
                                <button type="button" class="vo-player-btn" @click="setRate(0.25)" aria-label="أسرع">
                                    <x-icon name="plus" class="w-3.5 h-3.5" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            {{-- ---------- فارغ ---------- --}}
            <div x-show="history.length === 0 && running.length === 0" class="py-10 text-center">
                <span class="grid place-items-center w-12 h-12 mx-auto rounded-2xl bg-muted text-fg-subtle">
                    <x-icon name="mic" class="w-6 h-6" />
                </span>
                <p class="mt-3 text-sm font-semibold text-fg">لا تعليقات بعد</p>
                <p class="mt-1 text-xs text-fg-subtle leading-relaxed">ولّد أول تعليق صوتي ويظهر هنا للاستماع والتنزيل.</p>
            </div>
        </div>
    </div>
</aside>
