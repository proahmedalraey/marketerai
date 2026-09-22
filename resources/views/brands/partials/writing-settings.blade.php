{{--
    إعدادات الكتابة: تُطبَّق على كل منشور كما هي، فلا تمر بالتوليد
    ولا تجعل الأوصاف متأخرة. لذلك لها حفظها المستقل والمجاني.
--}}
@php $dialect = old('dialect', $brand->dialect ?? 'saudi'); @endphp

<x-section
    icon="pen" title="إعدادات الكتابة"
    description="تُطبَّق على كل منشور مباشرة — تعديلها لا يحتاج إعادة توليد ولا نقاطاً."
>
    <form method="POST" action="{{ route('brand.profile.settings') }}" class="space-y-4">
        @csrf
        @method('PUT')

        <div class="grid sm:grid-cols-2 gap-4">
            <x-field label="اللهجة" name="dialect" hint="تُختار افتراضياً في «كتابة المحتوى»، ويمكن تغييرها لكل محتوى.">
                <select id="dialect" name="dialect" class="field">
                    @foreach (config('dialects') as $value => $item)
                        <option value="{{ $value }}" @selected($dialect === $value)>{{ $item['label'] }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="نبرة العلامة" name="tone" optional>
                <input
                    id="tone" name="tone" type="text" maxlength="255"
                    class="field @error('tone') field-invalid @enderror"
                    value="{{ old('tone', $brand->tone) }}"
                    placeholder="مثال: خبير ودود، عملي، بلا مبالغة"
                >
            </x-field>

            <x-field label="كلمات ممنوعة" name="banned_words" optional hint="كلمة في كل سطر. لن تظهر في أي مخرج مهما كان القالب.">
                <textarea
                    id="banned_words" name="banned_words" rows="3" maxlength="500"
                    class="field @error('banned_words') field-invalid @enderror"
                    placeholder="الأفضل في العالم&#10;رخيص"
                >{{ old('banned_words', implode("\n", (array) $brand->banned_words)) }}</textarea>
            </x-field>

            <x-field label="رقم واتساب" name="whatsapp" optional hint="يُذكر في دعوة الإجراء حين يكون الهدف بيعاً مباشراً.">
                <input
                    id="whatsapp" name="whatsapp" type="tel" dir="ltr" inputmode="tel" maxlength="32"
                    class="field tnum @error('whatsapp') field-invalid @enderror"
                    value="{{ old('whatsapp', $brand->whatsapp) }}"
                    placeholder="9665xxxxxxxx"
                >
            </x-field>

            {{-- قواعد التاجر: لا يكتبها التوليد، فلا تمسحها إعادة التوليد --}}
            <div class="sm:col-span-2">
                <label for="content_rules" class="label">قواعدك <span class="font-normal text-fg-subtle">— اختياري</span></label>
                <textarea
                    id="content_rules" name="content_rules" rows="3" maxlength="2000"
                    class="field @error('content_rules') field-invalid @enderror"
                    placeholder="لا تذكر أسماء المنافسين&#10;اختم كل منشور بدعوة لزيارة الفروع"
                >{{ old('content_rules', implode("\n", (array) $brand->content_rules)) }}</textarea>

                @error('content_rules')
                    <ul class="error-text flex-col items-stretch gap-1">
                        @foreach ($errors->get('content_rules') as $message)
                            <li class="flex items-start gap-1.5"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px shrink-0" /><span>{{ $message }}</span></li>
                        @endforeach
                    </ul>
                @else
                    <p class="hint">
                        قاعدة في كل سطر، يلتزم بها كل منشور، ولا تتغير حين تعيد توليد الأوصاف.
                        المعلومات (توصيل، خصم، ضمان) مكانها <a href="{{ route('store.facts') }}" class="underline hover:text-fg">حقائق البيع</a>.
                    </p>
                @enderror
            </div>
        </div>

        <button type="submit" class="btn-secondary">
            <x-icon name="check" class="w-4 h-4" />
            <span>حفظ الإعدادات</span>
        </button>
    </form>
</x-section>
