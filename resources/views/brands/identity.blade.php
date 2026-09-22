@extends('layouts.app')
@section('title', 'الهوية البصرية')
@section('subtitle', 'حدّد ملامح هويتك ليجهّزها النظام تلقائياً على تصاميمك')

@php
    use Illuminate\Support\Facades\Storage;

    $disk = config('ai.media_disk', 'public');
    $patterns = array_values((array) $brand->patterns);
    $fontSlots = config('brand.font_slots');

    // الشكل الذي تحرّره Alpine: الدور قيمة نصية لا كائن enum
    $colorRows = old('colors') ?? collect($brand->paletteWithRoles())
        ->map(fn ($c) => ['hex' => $c['hex'], 'name' => $c['name'] ?? '', 'role' => $c['role']->value])
        ->all();
@endphp

@section('content')
<div class="space-y-5 max-w-4xl">

    {{-- ================= مكتبة الشعارات =================
         خارج نموذج الحفظ: لكل شعار فعله المستقل، ولا يصح أن ينتظر
         رفعُ صورةٍ ضغطةَ «حفظ» على الصفحة كلها. --}}
    <x-section
        icon="image"
        title="مكتبة الشعارات"
        description="الشعار المفضّل هو ما يُركَّب على تصاميمك. أضف نسخاً أخرى (مربعة، أحادية اللون) لتختار بينها لاحقاً."
    >
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">

            @foreach ($brand->logos as $logo)
                <div class="relative card p-2.5 flex flex-col gap-2 {{ $logo->is_default ? 'ring-1 ring-brand-500 border-brand-300' : '' }}">

                    <div class="relative aspect-[4/3] rounded-lg bg-white border border-line grid place-items-center overflow-hidden">
                        <img src="{{ $logo->url() }}" alt="{{ $logo->displayLabel() }}" class="max-w-full max-h-full object-contain p-2">

                        @if ($logo->is_default)
                            <span class="absolute top-1.5 start-1.5 chip-brand !py-0.5 !px-2 text-[10px] shadow-xs">
                                <x-icon name="check" class="w-3 h-3" />
                                <span>المفضل</span>
                            </span>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('brand.logos.update', $logo) }}" class="space-y-1.5">
                        @csrf
                        @method('PATCH')

                        <label class="sr-only" for="label-{{ $logo->id }}">اسم الشعار</label>
                        <input
                            id="label-{{ $logo->id }}" name="label" type="text" maxlength="60"
                            class="field !min-h-9 !text-xs !rounded-lg"
                            value="{{ $logo->label }}"
                            placeholder="مثال: شعار مربع"
                        >

                        <div class="flex items-center gap-1">
                            <button type="submit" class="btn-ghost btn-sm flex-1 text-fg-muted">
                                <x-icon name="check" class="w-3.5 h-3.5" />
                                <span>حفظ الاسم</span>
                            </button>

                            @unless ($logo->is_default)
                                <button
                                    type="submit" name="is_default" value="1"
                                    class="btn-secondary btn-sm"
                                    title="اجعله الشعار المفضّل"
                                >
                                    <x-icon name="sparkles" class="w-3.5 h-3.5" />
                                    <span class="sr-only">اجعله المفضّل</span>
                                </button>
                            @endunless
                        </div>
                    </form>

                    <div class="absolute top-1.5 end-1.5">
                        <x-confirm
                            :action="route('brand.logos.destroy', $logo)"
                            title="حذف الشعار؟"
                            :message="'سيُحذف «'.$logo->displayLabel().'» وملفه نهائياً. إن كان المفضّل، سيحلّ محله الشعار التالي.'"
                            confirm="حذف الشعار"
                            class="!bg-card/90 backdrop-blur-sm shadow-xs"
                        />
                    </div>
                </div>
            @endforeach

            {{-- بلاطة الإضافة: الرفع يبدأ فور الاختيار، بلا زر وسيط --}}
            <form
                method="POST" action="{{ route('brand.logos.store') }}" enctype="multipart/form-data"
                x-data class="contents"
            >
                @csrf
                <label class="product-add cursor-pointer !min-h-0 aspect-[4/3] gap-1.5">
                    <x-icon name="plus" class="w-6 h-6 text-fg-subtle" />
                    <span class="text-xs font-medium text-fg-muted">إضافة شعار</span>
                    <span class="text-[10px] text-fg-subtle">PNG شفاف · حتى ٢ ميغابايت</span>

                    <input
                        type="file" name="logo" accept="image/*" class="sr-only"
                        @change="$el.files.length && $el.form.requestSubmit()"
                    >
                </label>
            </form>
        </div>

        <p class="hint mt-3">
            يُركَّب الشعار برمجياً بعد توليد الصورة — لا نطلب من النموذج رسمه، لأن النماذج تشوّه الشعارات والنصوص العربية.
        </p>
    </x-section>

    {{-- ================= الأنماط والأشكال ================= --}}
    <x-section
        icon="layers"
        title="الأنماط والأشكال"
        :description="'صور مرجعية تُمرَّر للنموذج ليستلهم منها الشكل العام. حتى '.config('brand.patterns_max').' صور — والإكثار يشتّت النموذج لا يثريه.'"
    >
        <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-3">
            @foreach ($patterns as $i => $pattern)
                <div class="relative group">
                    <img
                        src="{{ Storage::disk($disk)->url($pattern['path']) }}"
                        alt="نمط {{ $i + 1 }}"
                        class="aspect-square w-full rounded-xl object-cover border border-line bg-muted"
                    >

                    <div class="absolute top-1 end-1">
                        <x-confirm
                            :action="route('brand.patterns.destroy', $i)"
                            title="حذف النمط؟"
                            message="ستُحذف الصورة نهائياً ولن تُمرَّر للنموذج بعد الآن."
                            confirm="حذف"
                            class="!bg-card/90 backdrop-blur-sm shadow-xs"
                        />
                    </div>
                </div>
            @endforeach

            @if ($patternSlots > 0)
                <form method="POST" action="{{ route('brand.patterns.store') }}" enctype="multipart/form-data" class="contents">
                    @csrf
                    <label class="product-add cursor-pointer !min-h-0 aspect-square gap-1">
                        <x-icon name="upload" class="w-5 h-5 text-fg-subtle" />
                        <span class="text-[11px] font-medium text-fg-muted">رفع صورة</span>
                        <span class="text-[10px] text-fg-subtle tnum">المتبقي {{ $patternSlots }}</span>

                        <input
                            type="file" name="patterns[]" accept="image/*" multiple class="sr-only"
                            @change="$el.files.length && $el.form.requestSubmit()"
                        >
                    </label>
                </form>
            @endif
        </div>

        @if ($patterns === [] && $patternSlots > 0)
            <p class="hint mt-3">مثال: خلفيات، زخارف، ملمس ورق، أو لقطات من تصاميم سابقة ترضى عن شكلها.</p>
        @endif
    </x-section>

    {{-- ================= النموذج الرئيسي ================= --}}
    <form
        method="POST" action="{{ route('brand.identity.update') }}" class="space-y-5"
        x-data="{
            max: {{ (int) config('brand.colors_max') }},
            colors: @js(array_values($colorRows)),

            add() {
                if (this.colors.length < this.max) {
                    this.colors.push({ hex: '#6F4E37', name: '', role: 'secondary' });
                }
            },

            remove(i) { this.colors.splice(i, 1); },
        }"
    >
        @csrf

        {{-- ---------------- الألوان ---------------- --}}
        <x-section
            icon="palette"
            title="الألوان"
            description="اللون بلا دور لا يفيد النموذج: عليه أن يعرف أيّها خلفية وأيّها نص وأيّها لون تمييز."
        >
            <div class="space-y-2.5">
                <template x-for="(color, i) in colors" :key="i">
                    <div class="flex flex-wrap items-center gap-2 panel p-2.5">

                        {{-- منتقي اللون والكود يتشاركان القيمة: العين تحكم قبل الكود --}}
                        <label class="relative w-10 h-10 shrink-0 rounded-lg border border-line-strong overflow-hidden cursor-pointer">
                            <span class="sr-only">اختر اللون</span>
                            <input
                                type="color" x-model="color.hex"
                                class="absolute -inset-2 w-[calc(100%+1rem)] h-[calc(100%+1rem)] cursor-pointer border-0 p-0 bg-transparent"
                            >
                        </label>

                        <input
                            type="text" x-model="color.hex" :name="`colors[${i}][hex]`"
                            dir="ltr" maxlength="7" placeholder="#6F4E37"
                            class="field !min-h-10 w-28 shrink-0 uppercase tnum text-xs"
                        >

                        <input
                            type="text" x-model="color.name" :name="`colors[${i}][name]`"
                            maxlength="40" placeholder="اسم اللون"
                            class="field !min-h-10 flex-1 min-w-[8rem] text-xs"
                        >

                        <select :name="`colors[${i}][role]`" x-model="color.role" class="field !min-h-10 w-32 shrink-0 text-xs">
                            @foreach ($roles as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        <button type="button" @click="remove(i)" class="btn-ghost btn-sm btn-icon text-fg-subtle hover:text-danger-fg">
                            <x-icon name="close" class="w-4 h-4" />
                            <span class="sr-only">حذف اللون</span>
                        </button>
                    </div>
                </template>
            </div>

            <p x-show="!colors.length" x-cloak class="text-sm text-fg-subtle py-2">
                لا ألوان بعد. أضف لوناً رئيسياً على الأقل.
            </p>

            <button type="button" @click="add()" x-show="colors.length < max" class="btn-ghost btn-sm mt-2 text-brand-700">
                <x-icon name="plus" class="w-4 h-4" />
                <span>إضافة لون</span>
            </button>
        </x-section>

        {{-- ---------------- الخطوط ---------------- --}}
        <x-section icon="label" title="الخطوط" description="اسم الخط كما هو معروف. يُستخدم في التصاميم التي نركّب نصوصها برمجياً.">
            <div class="grid sm:grid-cols-2 gap-4">
                @foreach ($fontSlots as $slot => $meta)
                    <x-field :label="$meta['label']" :name="'fonts.'.$slot" :for="'font-'.$slot" optional>
                        <input
                            id="font-{{ $slot }}" name="fonts[{{ $slot }}]" type="text" maxlength="60"
                            class="field @error('fonts.'.$slot) field-invalid @enderror"
                            value="{{ old('fonts.'.$slot, $brand->font($slot)) }}"
                            placeholder="مثال: {{ $meta['example'] }}"
                            dir="auto"
                        >
                    </x-field>
                @endforeach
            </div>
        </x-section>

        {{-- ---------------- التوجيه الأسلوبي ---------------- --}}
        <x-section icon="pen" title="التوجيه الأسلوبي" description="ما يُحقن نصاً في برومبت الصور. كلما كان محدداً، قلّ التكرار في المخرجات.">
            <x-field
                label="النمط البصري" name="visual_style" optional
                hint="سطر واحد يصف طريقة التصوير أو الإخراج."
            >
                <input
                    id="visual_style" name="visual_style" type="text" maxlength="255"
                    class="field @error('visual_style') field-invalid @enderror"
                    value="{{ old('visual_style', $brand->visual_style) }}"
                    placeholder="مثال: تصوير دافئ بإضاءة طبيعية وخلفيات بسيطة"
                >
            </x-field>

            <x-field
                label="الخلاصة التصميمية" name="design_summary" optional class="mt-4"
                hint="فقرة تجمع روح الهوية: الإحساس العام، ما تتجنبه، وما يميّز تصاميمك."
            >
                <textarea
                    id="design_summary" name="design_summary" rows="4" maxlength="2000"
                    class="field @error('design_summary') field-invalid @enderror"
                    placeholder="مثال: تصاميم عصرية بألوان هادئة تعكس الفخامة والبساطة، مع تركيز على المساحات البيضاء والخطوط النظيفة."
                >{{ old('design_summary', $brand->design_summary) }}</textarea>
            </x-field>
        </x-section>

        {{-- شريط الحفظ الثابت --}}
        <div class="sticky bottom-0 -mx-4 sm:-mx-6 px-4 sm:px-6 py-3 pb-safe glass border-t border-line z-20">
            <div class="flex items-center gap-2.5">
                <button type="submit" class="btn-primary">
                    <x-icon name="check" class="w-4 h-4" />
                    <span>حفظ الهوية البصرية</span>
                </button>

                <p class="hint !mt-0 hidden sm:block">الشعارات والأنماط تُحفظ فور رفعها — هذا الزر للألوان والخطوط والتوجيه.</p>
            </div>
        </div>
    </form>
</div>
@endsection
