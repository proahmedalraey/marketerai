@extends('layouts.app')

@section('title', 'إعدادات الذكاء الاصطناعي')
@section('subtitle', 'اربط مفاتيح API واختر المزود والنموذج الذي تعمل به المنصة')

@php
    $sourceChip = function (?string $source) {
        return match ($source) {
            'db' => ['chip-success', 'check-circle', 'محفوظ من الواجهة'],
            'env' => ['chip-info', 'file-text', 'من ملف البيئة'],
            default => ['chip-warning', 'alert', 'غير مضبوط'],
        };
    };

    $providers = [
        'anthropic' => [
            'name' => 'Anthropic',
            'tagline' => 'نماذج Claude — لكتابة المحتوى والهوية.',
            'keyPlaceholder' => 'sk-ant-...',
            'keyUrl' => 'https://console.anthropic.com/settings/keys',
            'models' => [
                ['field' => 'anthropic.model', 'input' => 'anthropic_model', 'label' => 'نموذج النصوص', 'kind' => 'text',
                 'suggest' => ['claude-sonnet-5', 'claude-opus-5', 'claude-haiku-4-5']],
            ],
            'baseUrl' => ['field' => 'anthropic.base_url', 'input' => 'anthropic_base_url'],
            'uses' => 'النصوص',
        ],
        'openai' => [
            'name' => 'OpenAI',
            'tagline' => 'نماذج GPT للنصوص و GPT Image لتوليد الصور.',
            'keyPlaceholder' => 'sk-...',
            'keyUrl' => 'https://platform.openai.com/api-keys',
            'models' => [
                ['field' => 'openai.model', 'input' => 'openai_model', 'label' => 'نموذج النصوص', 'kind' => 'text',
                 'suggest' => ['gpt-4.1', 'gpt-4.1-mini', 'gpt-4o']],
                ['field' => 'openai.image_model', 'input' => 'openai_image_model', 'label' => 'نموذج الصور', 'kind' => 'image',
                 'suggest' => ['gpt-image-1']],
            ],
            'baseUrl' => ['field' => 'openai.base_url', 'input' => 'openai_base_url'],
            'uses' => 'النصوص والصور',
        ],
        'gemini' => [
            'name' => 'Google Gemini',
            'tagline' => 'نماذج Gemini للنصوص، و Nano Banana لتوليد الصور وتحرير صور المنتجات.',
            'keyPlaceholder' => 'AIza...',
            'keyUrl' => 'https://aistudio.google.com/app/apikey',
            'models' => [
                ['field' => 'gemini.model', 'input' => 'gemini_model', 'label' => 'نموذج النصوص', 'kind' => 'text',
                 'suggest' => ['gemini-3.8-flash', 'gemini-3.6-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-pro-preview']],
                ['field' => 'gemini.image_model', 'input' => 'gemini_image_model', 'label' => 'نموذج الصور', 'kind' => 'image',
                 'suggest' => ['gemini-3.1-flash-image', 'gemini-3-pro-image', 'gemini-3.1-flash-lite-image']],
            ],
            'baseUrl' => ['field' => 'gemini.base_url', 'input' => 'gemini_base_url'],
            'uses' => 'النصوص والصور',
        ],
        'openrouter' => [
            'name' => 'OpenRouter',
            'tagline' => 'مفتاح واحد لمئات النماذج (Claude وGPT وGemini وDeepSeek وغيرها) وعشرات نماذج الصور، بفاتورة واحدة.',
            'keyPlaceholder' => 'sk-or-v1-...',
            'keyUrl' => 'https://openrouter.ai/settings/keys',
            'models' => [
                ['field' => 'openrouter.model', 'input' => 'openrouter_model', 'label' => 'نموذج النصوص', 'kind' => 'text',
                 'suggest' => ['google/gemini-3.6-flash', 'anthropic/claude-sonnet-5', 'openai/gpt-6-luna']],
                ['field' => 'openrouter.image_model', 'input' => 'openrouter_image_model', 'label' => 'نموذج الصور', 'kind' => 'image',
                 'suggest' => ['google/gemini-3.1-flash-image', 'openai/gpt-image-2']],
            ],
            'baseUrl' => ['field' => 'openrouter.base_url', 'input' => 'openrouter_base_url'],
            'uses' => 'النصوص والصور',
        ],
    ];

    $textProvider = old('text_provider', $fields['ai.text_provider']['value']);
    $imageProvider = old('image_provider', $fields['ai.image_provider']['value']);
@endphp

@section('content')
    <form method="POST" action="{{ route('settings.ai.update') }}" class="space-y-5" autocomplete="off">
        @csrf
        @method('PUT')

        {{-- ================= المزود الفعّال ================= --}}
        <x-section
            icon="zap" title="المزود الفعّال"
            description="أي مزود تستخدمه المنصة الآن. التبديل فوري ولا يحتاج إعادة تشغيل الخادم."
        >
            <div class="grid sm:grid-cols-2 gap-4">
                <x-field label="مزود النصوص" name="text_provider" hint="للمنشورات والخطط وملف الهوية.">
                    <select id="text_provider" name="text_provider" class="field @error('text_provider') field-invalid @enderror">
                        @foreach ($textProviders as $value => $label)
                            <option value="{{ $value }}" @selected($textProvider === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="مزود الصور" name="image_provider" hint="لاستوديو الصور والكاروسيل.">
                    <select id="image_provider" name="image_provider" class="field @error('image_provider') field-invalid @enderror">
                        @foreach ($imageProviders as $value => $label)
                            <option value="{{ $value }}" @selected($imageProvider === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            @if ($textProvider === 'fake' || $imageProvider === 'fake')
                <div class="alert-info mt-4">
                    <x-icon name="info" class="w-5 h-5 shrink-0 mt-px" />
                    <p class="leading-relaxed">
                        وضع التجربة مفعّل — المخرجات نصوص وصور نموذجية لا تمر بأي نموذج حقيقي.
                        اختر مزوداً فعلياً بعد إضافة مفتاحه.
                    </p>
                </div>
            @endif
        </x-section>

        {{-- ================= المزودون ================= --}}
        @foreach ($providers as $id => $p)
            @php
                $keyField = "{$id}.api_key";
                [$chipClass, $chipIcon, $chipText] = $sourceChip($fields[$keyField]['source']);
                $inUse = in_array($id, [$textProvider, $imageProvider], true);
            @endphp

            <x-section icon="key" :title="$p['name']" :description="$p['tagline']">
                <x-slot:actions>
                    @if ($inUse)
                        <span class="chip-brand">مستخدم الآن</span>
                    @endif
                    <span class="{{ $chipClass }} gap-1">
                        <x-icon :name="$chipIcon" class="w-3.5 h-3.5" />
                        {{ $chipText }}
                    </span>
                </x-slot:actions>

                <div class="space-y-4" x-data="{ show: false, clear: false }">
                    <x-field
                        label="مفتاح API"
                        :name="$id.'_api_key'"
                        :hint="$fields[$keyField]['mask']
                            ? 'المفتاح الحالي: '.$fields[$keyField]['mask'].' — اترك الحقل فارغاً للإبقاء عليه.'
                            : 'لا يوجد مفتاح بعد.'"
                    >
                        <div class="flex gap-2">
                            <input
                                id="{{ $id }}_api_key" name="{{ $id }}_api_key"
                                :type="show ? 'text' : 'password'" type="password"
                                dir="ltr" maxlength="500" spellcheck="false" autocomplete="new-password"
                                :disabled="clear"
                                class="field font-mono text-sm flex-1 @error($id.'_api_key') field-invalid @enderror"
                                placeholder="{{ $fields[$keyField]['mask'] ?? $p['keyPlaceholder'] }}"
                            >
                            <button
                                type="button" @click="show = ! show"
                                class="btn btn-secondary btn-icon shrink-0"
                                :aria-pressed="show ? 'true' : 'false'"
                            >
                                <x-icon name="eye" class="w-[18px] h-[18px]" />
                                <span class="sr-only">إظهار المفتاح</span>
                            </button>
                        </div>
                    </x-field>

                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <a href="{{ $p['keyUrl'] }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1 text-brand-700 dark:text-brand-300 hover:underline">
                            <span>احصل على مفتاح من لوحة {{ $p['name'] }}</span>
                            <x-icon name="external" class="w-3.5 h-3.5" />
                        </a>

                        @if ($fields[$keyField]['source'] === 'db')
                            <label class="inline-flex items-center gap-2 text-danger-fg cursor-pointer">
                                <input type="checkbox" name="clear[]" value="{{ $keyField }}" x-model="clear" class="rounded">
                                <span>حذف المفتاح المحفوظ</span>
                            </label>
                        @endif
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4">
                        @foreach ($p['models'] as $m)
                            @php
                                $current = (string) old($m['input'], $fields[$m['field']]['value']);
                                // القائمة الحية من المزود إن أمكن جلبها، وإلا الاقتراحات الثابتة
                                $live = $catalogs[$id][$m['kind']] ?? null;
                                $options = $live ?: $m['suggest'];
                                $unavailable = $live && $current !== '' && ! in_array($current, $live, true);
                                // مئات النماذج (OpenRouter) لا تصلح قائمة منسدلة عادية: نضيف بحثاً
                                $searchable = count($options) > 40;
                                $hint = $live
                                    ? count($live).' نموذجاً متاحاً'.($id === 'openrouter' ? '' : ' لمفتاحك').' — القائمة من '.$p['name'].' مباشرة.'
                                    : ($fields[$keyField]['mask']
                                        ? 'تعذر جلب قائمة النماذج من '.$p['name'].' — هذه اقتراحات عامة.'
                                        : 'احفظ المفتاح لتظهر النماذج المتاحة لحسابك.');
                            @endphp

                            <x-field :label="$m['label']" :for="$m['input'].'_select'" :name="$m['input']" :hint="$hint">
                                <div
                                    x-data="{
                                        choice: @js(in_array($current, $options, true) || $unavailable ? $current : ($current === '' ? ($options[0] ?? '') : '__custom')),
                                        custom: @js($current),
                                        q: '',
                                        all: @js($searchable ? $options : []),
                                        // المختار يبقى ظاهراً ولو لم يطابق البحث، وإلا اختفى من القائمة وتغيّرت قيمته
                                        get shown() {
                                            const q = this.q.trim().toLowerCase();
                                            const list = q ? this.all.filter(o => o.toLowerCase().includes(q)) : this.all;
                                            const keep = this.choice && this.choice !== '__custom' && ! list.includes(this.choice);
                                            return (keep ? [this.choice, ...list] : list).slice(0, 200);
                                        },
                                    }"
                                    class="space-y-2"
                                >
                                    <input type="hidden" name="{{ $m['input'] }}" value="{{ $current }}"
                                           :value="choice === '__custom' ? custom : choice">

                                    @if ($searchable)
                                        <input
                                            type="search" x-model="q" dir="ltr" spellcheck="false"
                                            class="field text-sm" placeholder="ابحث: claude · gemini · gpt · deepseek…"
                                            aria-label="ابحث في النماذج"
                                        >

                                        <select
                                            id="{{ $m['input'] }}_select" x-model="choice" dir="ltr"
                                            class="field font-mono text-sm @error($m['input']) field-invalid @enderror"
                                        >
                                            <template x-for="o in shown" :key="o">
                                                <option :value="o" x-text="o" :selected="o === choice"></option>
                                            </template>
                                            <option value="__custom">اسم آخر…</option>
                                        </select>

                                        <p class="hint" x-show="q && shown.length === 0" x-cloak>
                                            لا نموذج بهذا الاسم — جرّب كلمة أخرى، أو اختر «اسم آخر…» واكتبه.
                                        </p>
                                    @else
                                        <select
                                            id="{{ $m['input'] }}_select" x-model="choice" dir="ltr"
                                            class="field font-mono text-sm @error($m['input']) field-invalid @enderror"
                                        >
                                            @if ($unavailable)
                                                <option value="{{ $current }}">{{ $current }} — غير متاح لحسابك</option>
                                            @endif
                                            @foreach ($options as $option)
                                                <option value="{{ $option }}" @selected($option === $current)>{{ $option }}</option>
                                            @endforeach
                                            <option value="__custom">اسم آخر…</option>
                                        </select>
                                    @endif

                                    <input
                                        x-show="choice === '__custom'" x-cloak x-model="custom"
                                        type="text" dir="ltr" maxlength="100" spellcheck="false"
                                        class="field font-mono text-sm" placeholder="{{ $m['suggest'][0] }}"
                                        aria-label="اسم النموذج"
                                    >

                                    @if ($unavailable)
                                        <p class="error-text">
                                            <x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" />
                                            <span>النموذج المحفوظ لم يعد متاحاً — اختر نموذجاً من القائمة واحفظ.</span>
                                        </p>
                                    @endif
                                </div>
                            </x-field>
                        @endforeach
                    </div>

                    <details class="group" @if ($errors->has($p['baseUrl']['input'])) open @endif>
                        <summary class="inline-flex items-center gap-1 text-sm text-fg-muted cursor-pointer select-none hover:text-fg">
                            <x-icon name="chevron-down" class="w-4 h-4 transition group-open:rotate-180" />
                            إعدادات متقدمة
                        </summary>

                        <x-field
                            class="mt-3" label="رابط الـ API" :name="$p['baseUrl']['input']" optional
                            hint="غيّره فقط عند استخدام بوابة وسيطة أو خادم متوافق."
                        >
                            <input
                                id="{{ $p['baseUrl']['input'] }}" name="{{ $p['baseUrl']['input'] }}" type="url"
                                dir="ltr" maxlength="255"
                                class="field font-mono text-sm @error($p['baseUrl']['input']) field-invalid @enderror"
                                value="{{ old($p['baseUrl']['input'], $fields[$p['baseUrl']['field']]['value']) }}"
                            >
                        </x-field>
                    </details>

                    <div class="pt-1 border-t border-line flex flex-wrap items-center gap-3">
                        <button
                            type="submit" form="test-{{ $id }}"
                            class="btn-secondary btn-sm mt-3"
                            @disabled(! $fields[$keyField]['mask'])
                        >
                            <x-icon name="refresh" class="w-4 h-4" />
                            <span>اختبار الاتصال</span>
                        </button>
                        <p class="text-xs text-fg-subtle mt-3">
                            يرسل طلباً قصيراً جداً بالمفتاح المحفوظ. احفظ التغييرات أولاً ثم اختبر.
                        </p>
                    </div>
                </div>
            </x-section>
        @endforeach

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="flex items-center gap-1.5 text-xs text-fg-subtle">
                <x-icon name="lock" class="w-4 h-4" />
                المفاتيح تُحفظ مشفّرة ولا تُعرض كاملة بعد الحفظ. ما لا تضبطه هنا يُؤخذ من ملف البيئة.
            </p>

            <button type="submit" class="btn-primary">
                <x-icon name="check" class="w-4 h-4" />
                <span>حفظ الإعدادات</span>
            </button>
        </div>
    </form>

    {{-- نماذج الاختبار خارج النموذج الرئيسي: لا تتداخل النماذج في HTML --}}
    @foreach (array_keys($providers) as $id)
        <form id="test-{{ $id }}" method="POST" action="{{ route('settings.ai.test') }}" class="hidden">
            @csrf
            <input type="hidden" name="provider" value="{{ $id }}">
        </form>
    @endforeach
@endsection
