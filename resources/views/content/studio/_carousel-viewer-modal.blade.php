{{--
    عارض الكاروسيل: يُفتح من «تكبير» أو «عرض الشرائح» على بطاقة الكاروسيل المكدّسة.
    المنطق في resources/js/carousel-viewer.js، والرسم نفسه في carousel-render.js.
    سطح داكن في الوضعين: الصور تُقرأ عليه أوضح، كعارض الصور الكامل.
--}}
<div
    x-data="carouselViewer"
    @carousel-viewer:open.window="openWith($event.detail)"
    @keydown.window="onKey($event)"
    @keydown.escape.window="open && close()"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[72]"
    role="dialog"
    aria-modal="true"
    aria-labelledby="carousel-viewer-title"
>
    <div x-show="open" x-transition.opacity.duration.200ms @click="close()" class="absolute inset-0 bg-scrim/85 backdrop-blur-md" aria-hidden="true"></div>

    <div class="relative h-full grid place-items-center p-2 sm:p-6 pointer-events-none">
        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-[.98]"
             x-transition:enter-end="opacity-100 scale-100"
             class="pointer-events-auto relative w-full max-w-5xl h-full max-h-[920px] flex flex-col rounded-3xl overflow-hidden
                    bg-[rgb(22_18_15)] text-white shadow-2xl ring-1 ring-white/10">

            {{-- الرأس: على الجوال سطران (العنوان والإغلاق، ثم الأدوات)، وعلى الحاسوب سطر واحد --}}
            <header class="flex flex-wrap items-center gap-2 px-4 sm:px-5 py-3 border-b border-white/10 shrink-0">
                <span class="order-1 grid place-items-center w-9 h-9 rounded-xl bg-white/10 shrink-0">
                    <x-icon name="layers" class="w-4 h-4" />
                </span>
                <div class="order-1 flex-1 min-w-0">
                    <h2 id="carousel-viewer-title" class="text-sm font-bold text-white truncate" x-text="data?.title || 'كاروسيل'"></h2>
                    <p class="text-[11px] text-white/60" x-show="data">
                        <span class="tnum" x-text="number(total)"></span> شرائح ·
                        <span class="tnum" x-text="number(ready)"></span> بصور
                    </p>
                </div>

                <button type="button" x-ref="close" @click="close()"
                        class="order-2 sm:order-4 grid place-items-center w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 transition shrink-0">
                    <x-icon name="close" class="w-4 h-4" />
                    <span class="sr-only">إغلاق</span>
                </button>

                <div class="order-3 w-full sm:w-auto flex items-center gap-2">
                    {{-- بالنص كما سيُنشر، أو الصور وحدها --}}
                    <div class="flex items-center p-1 rounded-full bg-white/10" role="group" aria-label="طريقة العرض">
                        <button type="button" @click="setMode('composed')" :aria-pressed="mode === 'composed' ? 'true' : 'false'"
                                :class="mode === 'composed' ? 'bg-white text-gray-900 font-semibold' : 'text-white/70 hover:text-white'"
                                class="px-3 min-h-8 rounded-full text-xs transition">كما سيُنشر</button>
                        <button type="button" @click="setMode('photo')" :aria-pressed="mode === 'photo' ? 'true' : 'false'"
                                :class="mode === 'photo' ? 'bg-white text-gray-900 font-semibold' : 'text-white/70 hover:text-white'"
                                class="px-3 min-h-8 rounded-full text-xs transition">الصور فقط</button>
                    </div>

                    <button type="button" @click="edit()" :disabled="! data"
                            class="ms-auto sm:ms-0 inline-flex items-center gap-1.5 px-3 min-h-9 rounded-full bg-white/10 text-xs font-semibold hover:bg-white/20 disabled:opacity-40 transition">
                        <x-icon name="pencil" class="w-3.5 h-3.5" />
                        تعديل
                        <span class="hidden md:inline">الكاروسيل</span>
                    </button>
                    <button type="button" @click="downloadAll()" :disabled="! data || downloading"
                            class="grid place-items-center w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 disabled:opacity-40 transition shrink-0"
                            title="تنزيل كل الشرائح كما ستُنشر">
                        <x-icon name="download" class="w-4 h-4" ::class="downloading && 'motion-safe:animate-pulse'" />
                        <span class="sr-only">تنزيل كل الشرائح</span>
                    </button>
                    <a :href="data?.urls?.content" x-show="data" class="grid place-items-center w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 transition shrink-0"
                       title="فتح المحتوى (الكابشن والنشر)">
                        <x-icon name="external" class="w-4 h-4" />
                        <span class="sr-only">فتح المحتوى</span>
                    </a>
                </div>
            </header>

            {{-- التحميل والخطأ --}}
            <div x-show="loading" class="flex-1 grid place-items-center text-sm text-white/70">
                <span class="flex items-center gap-2">
                    <x-icon name="refresh" class="w-4 h-4 motion-safe:animate-spin" />
                    يفتح الكاروسيل…
                </span>
            </div>
            <div x-show="! loading && error" x-cloak class="flex-1 grid place-items-center p-6">
                <p class="text-sm text-danger-fg bg-danger-soft rounded-xl px-4 py-3" x-text="error"></p>
            </div>

            <template x-if="! loading && ! error && data">
                <div class="flex-1 min-h-0 flex flex-col">
                    {{-- المسرح --}}
                    <div class="relative flex-1 min-h-0 flex items-center justify-center px-11 sm:px-20 py-3 sm:py-4 select-none touch-pan-y"
                         @pointerdown="pointerStart($event)" @pointerup="pointerEnd($event)" @pointercancel="pointerX = null">

                        {{-- الشريحة بحجمها الطبيعي داخل المساحة المتاحة (max-h/max-w) فلا تتشوّه نسبتها --}}
                        <div class="relative h-full w-full flex items-center justify-center">
                            <canvas x-ref="stage" x-show="mode === 'composed'"
                                    class="max-h-full max-w-full w-auto h-auto rounded-2xl shadow-2xl bg-white/5"
                                    :aria-label="`الشريحة ${index + 1}: ${slideText(slide)}`" role="img"></canvas>

                            <template x-if="mode === 'photo' && photoUrl">
                                <img :src="photoUrl" :alt="`صورة الشريحة ${index + 1}`"
                                     class="max-h-full max-w-full w-auto h-auto rounded-2xl shadow-2xl bg-white/5">
                            </template>
                            <template x-if="mode === 'photo' && ! photoUrl">
                                <div class="h-full max-w-full rounded-2xl bg-white/5 grid place-items-center text-center p-6 text-white/60 text-sm"
                                     :style="`aspect-ratio: ${ratioCss}`">
                                    <span>
                                        <x-icon name="image" class="w-8 h-8 mx-auto mb-2 opacity-60" />
                                        لا صورة لهذه الشريحة بعد
                                    </span>
                                </div>
                            </template>
                        </div>

                        {{-- RTL: السابق يميناً والتالي يساراً (مثل «اسحب ←» على الشرائح) --}}
                        <button type="button" @click="prev()" :disabled="index === 0"
                                class="absolute right-2 sm:right-5 top-1/2 -translate-y-1/2 grid place-items-center w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 disabled:opacity-25 transition"
                                aria-label="الشريحة السابقة">
                            <x-icon name="chevron-right" class="w-5 h-5" />
                        </button>
                        <button type="button" @click="next()" :disabled="index >= total - 1"
                                class="absolute left-2 sm:left-5 top-1/2 -translate-y-1/2 grid place-items-center w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 disabled:opacity-25 transition"
                                aria-label="الشريحة التالية">
                            <x-icon name="chevron-left" class="w-5 h-5" />
                        </button>
                    </div>

                    {{-- نص الشريحة الحالية --}}
                    <div class="px-5 sm:px-8 pt-3 pb-2 shrink-0" aria-live="polite">
                        <div class="max-w-2xl mx-auto flex items-start gap-3">
                            <span class="shrink-0 mt-0.5 px-2.5 py-0.5 rounded-full bg-white/10 text-[11px] font-semibold tnum" x-text="counter"></span>
                            <p class="flex-1 min-w-0 text-sm leading-relaxed text-white/85 line-clamp-2">
                                <span class="font-semibold text-brand-300" x-text="`الشريحة ${number(index + 1)}:`"></span>
                                <span x-text="slideText(slide)"></span>
                            </p>
                            <span x-show="! hasPhoto(slide)" class="shrink-0 px-2 py-0.5 rounded-full bg-warning/20 text-warning text-[10px] font-semibold">بلا صورة بعد</span>
                            <button type="button" @click="download()" :disabled="downloading"
                                    class="shrink-0 inline-flex items-center gap-1 text-[11px] font-semibold text-white/70 hover:text-white disabled:opacity-40">
                                <x-icon name="download" class="w-3.5 h-3.5" />
                                هذه الشريحة
                            </button>
                        </div>
                    </div>

                    {{-- شريط المصغرات --}}
                    <div class="shrink-0 px-4 pb-4 pt-1">
                        {{-- w-max + mx-auto: يتوسط حين يتسع، ويُمرَّر من أوله حين يزيد (بدل justify-center الذي يقصّ البداية) --}}
                        <div class="overflow-x-auto py-1 px-1">
                        <div class="flex gap-2 w-max mx-auto" role="tablist" aria-label="شرائح الكاروسيل">
                            <template x-for="(item, i) in slides" :key="i">
                                <button type="button" role="tab" :data-thumb="i" @click="go(i)"
                                        :aria-selected="index === i ? 'true' : 'false'"
                                        :class="index === i ? 'ring-2 ring-brand-400 opacity-100' : 'opacity-60 hover:opacity-100'"
                                        class="relative shrink-0 w-14 sm:w-16 rounded-lg overflow-hidden bg-white/5 transition"
                                        :style="`aspect-ratio: ${ratioCss}`">
                                    <canvas x-show="mode === 'composed'" :data-thumb-canvas="i" class="w-full h-full"></canvas>
                                    <img x-show="mode === 'photo' && hasPhoto(item)" :src="(data.thumbs || {})[item.origin] || ''" alt="" class="w-full h-full object-cover">
                                    <span x-show="mode === 'photo' && ! hasPhoto(item)" class="absolute inset-0 grid place-items-center border border-dashed border-white/25 rounded-lg">
                                        <x-icon name="image" class="w-4 h-4 opacity-50" />
                                    </span>
                                    <span class="absolute bottom-0 inset-x-0 text-[10px] font-bold bg-black/55 tnum" x-text="number(i + 1)"></span>
                                </button>
                            </template>
                        </div>
                        </div>
                        <p class="hidden sm:block mt-2 text-center text-[11px] text-white/40">الأسهم أو السحب للتنقّل · Esc للإغلاق</p>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
