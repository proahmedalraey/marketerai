@php
    // الصف الأول (تلقائي، فصحى، سعودية) ثم لهجات السعودية الفرعية تحته، ثم البقية
    $dialectKeys = array_keys($studio['dialects']);
    $firstRow = array_slice($dialectKeys, 0, 3);
    $restRows = array_slice($dialectKeys, 3);
@endphp

{{-- ================= 3. اللغة واللهجة ================= --}}
<section class="vo-card" aria-labelledby="vo-language-title">
    <button type="button" class="vo-head" @click="open.language = ! open.language" :aria-expanded="open.language ? 'true' : 'false'">
        <span class="vo-head-icon"><x-icon name="languages" class="w-[18px] h-[18px]" /></span>
        <h2 id="vo-language-title" class="text-[15px] font-bold text-fg">اللغة واللهجة</h2>
        <span class="vo-head-check"><x-icon name="check" class="w-3.5 h-3.5" /></span>
        <span class="text-xs text-fg-subtle truncate" x-text="languageSummary"></span>
        <x-icon name="chevron-up" class="ms-auto w-5 h-5 text-fg-subtle transition-transform duration-200" x-bind:class="! open.language && 'rotate-180'" />
    </button>

    <div x-show="open.language" class="border-t border-line px-4 sm:px-6 py-5 space-y-4">
        <div class="flex items-center gap-2">
            <span class="vo-sub-icon"><x-icon name="globe" class="w-4 h-4" /></span>
            <h3 class="text-sm font-bold text-fg">اللغة</h3>
        </div>

        <div class="vo-seg" role="radiogroup" aria-label="اللغة">
            <button type="button" role="radio" class="vo-seg-btn" :aria-pressed="language === 'ar' ? 'true' : 'false'" :aria-checked="language === 'ar' ? 'true' : 'false'" @click="setLanguage('ar')">العربية</button>
            <button type="button" role="radio" class="vo-seg-btn" :aria-pressed="language === 'en' ? 'true' : 'false'" :aria-checked="language === 'en' ? 'true' : 'false'" @click="setLanguage('en')" lang="en">English</button>
        </div>

        {{-- ---------- اللهجات العربية ---------- --}}
        <div x-show="language === 'ar'" class="space-y-2" role="radiogroup" aria-label="اللهجة العربية">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach ($firstRow as $key)
                    @php $hasVariants = (bool) $studio['dialects'][$key]['variants']; @endphp

                    <button
                        type="button" role="radio" class="vo-option" :aria-checked="dialect === '{{ $key }}' ? 'true' : 'false'" @click="chooseDialect('{{ $key }}')"
                        @if ($hasVariants) aria-controls="vo-variants-{{ $key }}" :aria-expanded="dialect === '{{ $key }}' ? 'true' : 'false'" @endif
                    >
                        {{ $studio['dialects'][$key]['label'] }}
                        @if ($hasVariants)
                            {{-- لهجاتها الفرعية منطوية تحتها: تنفتح حين تُختار --}}
                            <x-icon name="chevron-down" class="ms-auto w-4 h-4 opacity-60 transition-transform duration-200" x-bind:class="dialect === '{{ $key }}' && 'rotate-180'" />
                        @endif
                    </button>
                @endforeach
            </div>

            @foreach ($studio['dialects'] as $key => $dialect)
                @if ($dialect['variants'])
                    <div
                        id="vo-variants-{{ $key }}"
                        x-show="dialect === '{{ $key }}'" x-cloak
                        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                        class="grid grid-cols-2 sm:grid-cols-3 gap-2 ps-3 border-s-2 border-fg"
                        role="group" aria-label="لهجات {{ $dialect['label'] }}"
                    >
                        @foreach ($dialect['variants'] as $variantKey => $variantLabel)
                            <button
                                type="button" role="radio" class="vo-option"
                                :aria-checked="variant === '{{ $variantKey }}' ? 'true' : 'false'"
                                @click="chooseVariant('{{ $variantKey }}')"
                            >{{ $variantLabel }}</button>
                        @endforeach
                    </div>
                @endif
            @endforeach

            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach ($restRows as $key)
                    <button type="button" role="radio" class="vo-option" :aria-checked="dialect === '{{ $key }}' ? 'true' : 'false'" @click="chooseDialect('{{ $key }}')">
                        {{ $studio['dialects'][$key]['label'] }}
                    </button>
                @endforeach
            </div>

            <p class="hint">«تلقائي» يحدد اللهجة حسب محتوى النص.</p>
        </div>

        {{-- ---------- اللكنات الإنجليزية ---------- --}}
        <div x-show="language === 'en'" x-cloak class="space-y-3">
            <p class="text-xs text-fg-subtle">اللهجات العربية غير مطبّقة في الوضع الإنجليزي.</p>

            <div class="flex items-center gap-2">
                <span class="vo-sub-icon"><x-icon name="languages" class="w-4 h-4" /></span>
                <h3 class="text-sm font-bold text-fg">اللهجة الإنجليزية</h3>
            </div>

            <div class="grid sm:grid-cols-2 gap-2" role="radiogroup" aria-label="اللهجة الإنجليزية">
                @foreach ($studio['accents'] as $key => $accent)
                    <button type="button" role="radio" class="vo-option" :aria-checked="accent === '{{ $key }}' ? 'true' : 'false'" @click="accent = '{{ $key }}'">
                        {{ $accent['label'] }} — <span lang="en" class="ms-1">{{ $accent['en'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</section>
