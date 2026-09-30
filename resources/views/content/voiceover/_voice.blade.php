{{-- ================= 4. إعدادات الصوت ================= --}}
<section class="vo-card" aria-labelledby="vo-voice-title">
    <button type="button" class="vo-head" @click="open.voice = ! open.voice" :aria-expanded="open.voice ? 'true' : 'false'">
        <span class="vo-head-icon"><x-icon name="volume" class="w-[18px] h-[18px]" /></span>
        <h2 id="vo-voice-title" class="text-[15px] font-bold text-fg">إعدادات الصوت</h2>
        <span x-show="voice" class="vo-head-check"><x-icon name="check" class="w-3.5 h-3.5" /></span>
        <span class="text-xs text-fg-subtle truncate" x-text="selectedVoice?.name ?? ''"></span>
        @include('content.voiceover._toggle', ['open' => 'open.voice', 'class' => 'ms-auto'])
    </button>

    <div x-show="open.voice" class="border-t border-line px-4 sm:px-6 py-5 space-y-4">

        {{-- ---------- المذيع ---------- --}}
        <div class="flex items-center gap-2">
            <span class="vo-sub-icon"><x-icon name="volume" class="w-4 h-4" /></span>
            <h3 id="vo-narrator-title" class="text-sm font-bold text-fg">المذيع</h3>
        </div>

        <div class="vo-seg" role="group" aria-label="تصفية المذيعين">
            <button type="button" class="vo-seg-btn" :aria-pressed="gender === 'all' ? 'true' : 'false'" @click="gender = 'all'">الكل</button>
            <button type="button" class="vo-seg-btn" :aria-pressed="gender === 'female' ? 'true' : 'false'" @click="gender = 'female'">إناث</button>
            <button type="button" class="vo-seg-btn" :aria-pressed="gender === 'male' ? 'true' : 'false'" @click="gender = 'male'">ذكور</button>
        </div>

        <div class="relative sm:max-w-md">
            <x-icon name="search" class="absolute start-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-fg-subtle pointer-events-none" />
            <input type="search" x-model="voiceSearch" class="field ps-10" placeholder="ابحث عن صوت…" aria-label="ابحث عن صوت">
        </div>

        <div class="vo-scroll max-h-[23rem] -mx-1 px-1 pe-2 py-1">
            <div role="radiogroup" aria-labelledby="vo-narrator-title" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5">
                <template x-for="v in filteredVoices" :key="v.key">
                    <div
                        role="radio" tabindex="0" class="vo-voice"
                        :aria-checked="voice === v.key ? 'true' : 'false'"
                        :aria-label="`${v.name} — ${v.tone}`"
                        @click="voice = v.key"
                        @keydown.enter.prevent="voice = v.key"
                        @keydown.space.prevent="voice = v.key"
                    >
                        <button
                            type="button" @click.stop="toggleFavorite(v.key)"
                            class="absolute top-1.5 start-1.5 grid place-items-center w-7 h-7 rounded-lg opacity-60 hover:opacity-100 transition"
                            :aria-label="isFavorite(v.key) ? `إزالة ${v.name} من المفضلة` : `إضافة ${v.name} للمفضلة`"
                            :aria-pressed="isFavorite(v.key) ? 'true' : 'false'"
                        >
                            <x-icon name="star" class="w-4 h-4" x-bind:class="isFavorite(v.key) && 'fill-current text-brand-500 opacity-100'" />
                        </button>

                        <span x-show="voice === v.key" class="absolute top-2 end-2 grid place-items-center w-5 h-5 rounded-full bg-fg-inverse text-fg">
                            <x-icon name="check" stroke="3" class="w-3 h-3" />
                        </span>

                        <span class="vo-avatar" :style="`--h: ${hue(v.key)}`">
                            <template x-if="v.avatar"><img :src="v.avatar" alt="" class="w-full h-full rounded-full object-cover"></template>
                            <span x-show="! v.avatar" x-text="v.initial"></span>
                        </span>

                        <span class="text-sm font-semibold" x-text="v.name"></span>

                        <button
                            type="button" class="vo-listen" @click.stop="previewVoice(v)"
                            :aria-label="sample.voice === v.key && sample.playing ? `إيقاف عينة ${v.name}` : `استمع لعينة ${v.name}`"
                        >
                            <span x-show="sample.voice === v.key && sample.loading" class="w-2.5 h-2.5 rounded-full border-[1.5px] border-current border-t-transparent animate-spin"></span>
                            <x-icon name="pause" class="w-2.5 h-2.5" x-show="sample.voice === v.key && sample.playing" />
                            <x-icon name="play" class="w-2.5 h-2.5" x-show="sample.voice !== v.key || (! sample.loading && ! sample.playing)" />
                            <span x-text="sample.voice === v.key && sample.playing ? 'إيقاف' : 'استمع'">استمع</span>
                        </button>
                    </div>
                </template>
            </div>

            <p x-show="filteredVoices.length === 0" class="py-8 text-center text-sm text-fg-subtle">لا مذيع بهذا الاسم.</p>
        </div>

        {{-- المذيع المختار --}}
        <div x-show="selectedVoice" class="flex items-center gap-3 rounded-xl border border-line bg-muted/60 px-3.5 py-2.5">
            <span class="vo-avatar w-9 h-9 text-sm" :style="`--h: ${hue(voice)}`">
                <template x-if="selectedVoice?.avatar"><img :src="selectedVoice.avatar" alt="" class="w-full h-full rounded-full object-cover"></template>
                <span x-show="! selectedVoice?.avatar" x-text="selectedVoice?.initial"></span>
            </span>
            <span class="text-sm font-bold text-fg" x-text="selectedVoice?.name"></span>
            <span class="text-fg-subtle" aria-hidden="true">·</span>
            <span class="text-xs text-fg-muted truncate" x-text="selectedVoice?.tone"></span>
        </div>

        <hr class="divider">

        {{-- ---------- جودة الصوت ---------- --}}
        <div class="flex items-center gap-2">
            <span class="vo-sub-icon"><x-icon name="zap" class="w-4 h-4" /></span>
            <h3 id="vo-tier-title" class="text-sm font-bold text-fg">جودة الصوت</h3>
        </div>

        <div role="radiogroup" aria-labelledby="vo-tier-title" class="space-y-2">
            @foreach ($studio['tiers'] as $key => $item)
                <button type="button" role="radio" class="vo-tier" :aria-checked="tier === '{{ $key }}' ? 'true' : 'false'" @click="tier = '{{ $key }}'">
                    <span class="vo-radio" aria-hidden="true"></span>
                    <x-icon :name="$item['icon']" class="w-4 h-4 {{ $key === 'hd' ? 'text-info' : 'text-brand-500' }}" />
                    <span class="font-semibold" dir="ltr">{{ $item['label'] }}</span>
                    <span class="opacity-80">— {{ $item['hint'] }}</span>
                    <span class="ms-auto text-[11px] tnum opacity-75 whitespace-nowrap">{{ \App\Support\Credits::format($studio['minuteCosts'][$key] ?? 0) }} نقطة/دقيقة</span>
                </button>
            @endforeach
        </div>
    </div>
</section>
