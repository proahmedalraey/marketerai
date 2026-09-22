{{--
    أسئلة المشروع — المصدر الوحيد لكل ما يُكتب عن العلامة.
    نوع النشاط يغيّر صياغة سؤالين، والتلميحات تنبّه للإجابة الشحيحة قبل التوليد.

    @param array $answers      الإجابات الحالية من أعمدة العلامة
    @param array $questions    نصوص الأسئلة لكل نوع: [good => [...], service => [...]]
    @param bool  $first        لا أوصاف بعد: الحفظ ينشئها (مجاناً) بدل أن يكتفي بالحفظ
    @param int   $prefillCost  تكلفة «عبّئ من الرابط» (0 = مجاني)
--}}
@php
    $value = fn (string $key) => old($key, $answers[$key] ?? '');

    // شارة تظهر بجانب كل حقل عُبّئ من صفحة المتجر: اقتراح يُراجَع لا حقيقة
    $fromStore = fn (string $key) => '<span x-show="filledFromStore.includes(\''.$key.'\')" x-cloak class="chip-info !py-0 !px-2 text-[10px] ms-1">من المتجر — راجعه</span>';
@endphp

<form
    method="POST" action="{{ route('brand.profile.answers') }}"
    x-data="brandAnswers(@js([
        'type' => $value('type') ?: 'good',
        'questions' => $questions,
        'prefillUrl' => route('brand.profile.prefill'),
        'fields' => [
            'project_name' => $value('project_name'),
            'store_url' => $value('store_url'),
            'one_liner' => $value('one_liner'),
            'advantages' => $value('advantages'),
            'audience' => $value('audience'),
        ],
    ]))"
    class="space-y-5"
>
    @csrf
    @method('PUT')

    <fieldset>
        <legend class="label">وش طبيعة نشاطك؟</legend>

        <div class="grid sm:grid-cols-2 gap-3">
            @foreach ([
                'good' => ['أبيع منتج (سلعة)', 'منتج ملموس تبيعه للعملاء', 'package'],
                'service' => ['أقدّم خدمة', 'خدمة احترافية أو استشارية', 'briefcase'],
            ] as $key => [$title, $hint, $icon])
                <label class="choice items-center">
                    <input type="radio" name="type" value="{{ $key }}" x-model="type" class="sr-only">
                    <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-muted text-fg-muted">
                        <x-icon :name="$icon" class="w-5 h-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-fg">{{ $title }}</span>
                        <span class="block text-xs text-fg-subtle mt-0.5">{{ $hint }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>

    {{-- ---------------- الرابط أولاً: منه تُعبّأ بقية الأسئلة ---------------- --}}
    <div class="min-w-0">
        <label for="store_url" class="label">
            رابط المتجر أو الصفحة
            <span class="font-normal text-fg-subtle">— اختياري</span>
        </label>

        <div class="flex flex-wrap gap-2">
            <input
                id="store_url" name="store_url" type="url" dir="ltr" inputmode="url" maxlength="255"
                x-model="f.store_url"
                class="field flex-1 min-w-[14rem] @error('store_url') field-invalid @enderror"
                placeholder="https://"
            >
            <button
                type="button" @click="prefill()" :disabled="!f.store_url || prefilling"
                class="btn-secondary shrink-0" :data-busy="prefilling"
            >
                <x-icon name="sparkles" class="w-4 h-4" />
                <span>عبّئ الأسئلة من الرابط</span>
                <span class="chip-brand !py-0.5 tnum">{{ $prefillCost > 0 ? $prefillCost.' نقطة' : 'مجاناً' }}</span>
            </button>
        </div>

        @error('store_url')
            <p class="error-text">{{ $message }}</p>
        @else
            <p x-show="!prefillMessage" class="hint">نقرأ صفحة متجرك ونقترح إجابات للأسئلة الفارغة — تراجعها قبل الحفظ.</p>
        @enderror

        <p
            x-show="prefillMessage" x-cloak x-text="prefillMessage" role="status" aria-live="polite"
            class="mt-1.5 text-xs font-medium" :class="prefillError ? 'text-danger-fg' : 'text-success-fg'"
        ></p>
    </div>

    <x-field label="اسم المشروع" name="project_name" required>
        <input
            id="project_name" name="project_name" type="text" maxlength="120" required
            x-model="f.project_name"
            class="field @error('project_name') field-invalid @enderror"
            placeholder="مثال: إمدادات القهوة"
        >
    </x-field>

    <div class="min-w-0">
        <label for="one_liner" class="label">
            <span x-text="questions[type].one_liner">{{ $questions['good']['one_liner'] }}</span>
            <span class="text-danger-fg" aria-hidden="true">*</span>
            {!! $fromStore('one_liner') !!}
        </label>
        <textarea
            id="one_liner" name="one_liner" rows="2" maxlength="1000" required
            x-model="f.one_liner"
            class="field @error('one_liner') field-invalid @enderror"
            placeholder="مثال: نبيع مواد ومعدات الكافيهات والمطاعم من سيروبات وصوصات وحبوب قهوة وأدوات باريستا"
        ></textarea>
        @error('one_liner') <p class="error-text">{{ $message }}</p> @enderror
        <p x-show="hints.one_liner" x-cloak x-text="hints.one_liner" class="hint !text-warning-fg"></p>
    </div>

    <div class="min-w-0">
        <label for="advantages" class="label">
            <span x-text="questions[type].advantages">{{ $questions['good']['advantages'] }}</span>
            <span class="text-danger-fg" aria-hidden="true">*</span>
            {!! $fromStore('advantages') !!}
        </label>
        <textarea
            id="advantages" name="advantages" rows="3" maxlength="1000" required
            x-model="f.advantages"
            class="field @error('advantages') field-invalid @enderror"
            placeholder="وكلاء لعدة علامات تجارية عالمية&#10;فروع متعددة&#10;توصيل مجاني للأنشطة التجارية داخل المدينة"
        ></textarea>
        @error('advantages')
            <p class="error-text">{{ $message }}</p>
        @else
            <p x-show="!hints.advantages" class="hint">ميزة في كل سطر.</p>
        @enderror
        <p x-show="hints.advantages" x-cloak x-text="hints.advantages" class="hint !text-warning-fg"></p>
    </div>

    <div class="min-w-0">
        <label for="audience" class="label">
            {{ $questions['good']['audience'] }}
            <span class="text-danger-fg" aria-hidden="true">*</span>
            {!! $fromStore('audience') !!}
        </label>
        <textarea
            id="audience" name="audience" rows="2" maxlength="1000" required
            x-model="f.audience"
            class="field @error('audience') field-invalid @enderror"
            placeholder="مثال: أصحاب المقاهي والباريستا في السعودية، ومحضّرو المشروبات في البيت"
        ></textarea>
        @error('audience')
            <p class="error-text">{{ $message }}</p>
        @else
            <p x-show="!hints.audience" class="hint">حدّد المهنة والمكان والمشكلة التي يعانيها.</p>
        @enderror
        <p x-show="hints.audience" x-cloak x-text="hints.audience" class="hint !text-warning-fg"></p>
    </div>

    <x-field
        :label="$questions['good']['notes']" name="notes" optional
        hint="أي شيء يجب أن يعرفه كاتب المحتوى ولا تغطيه الأسئلة السابقة."
    >
        <textarea
            id="notes" name="notes" rows="2" maxlength="1000"
            class="field @error('notes') field-invalid @enderror"
        >{{ $value('notes') }}</textarea>
    </x-field>

    <div class="flex flex-wrap items-center gap-2.5 pt-1">
        @if ($first)
            <button type="submit" class="btn-primary">
                <x-icon name="sparkles" class="w-4 h-4" />
                <span>حفظ وإنشاء الأوصاف</span>
                <span class="chip bg-white/15 text-white !py-0.5">مجاناً</span>
            </button>
        @else
            {{--
                «حفظ فقط» أولاً في ترتيب الصفحة: زر Enter في أي حقل يضغط أول زر إرسال،
                والمجاني هو ما يجب أن يحدث بلا قصد، لا الخصم.
            --}}
            <button type="submit" name="regenerate" value="0" class="btn-secondary">
                <x-icon name="check" class="w-4 h-4" />
                <span>حفظ فقط</span>
            </button>

            <button type="submit" name="regenerate" value="1" class="btn-primary">
                <x-icon name="refresh" class="w-4 h-4" />
                <span>حفظ وإعادة توليد الأوصاف</span>
                <span class="chip bg-white/15 text-white !py-0.5 tnum">{{ $costLabel ?? '' }}</span>
            </button>

            <button type="button" @click="$dispatch('close-answers')" class="btn-ghost"><span>إلغاء</span></button>
            <p class="hint !mt-0 w-full">«حفظ فقط» مجاني ولا يغيّر الأوصاف الحالية. «حفظ وإعادة توليد» يكتب الأوصاف الثلاثة من إجاباتك الجديدة.</p>
        @endif
    </div>
</form>
