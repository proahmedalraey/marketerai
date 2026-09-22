@extends('layouts.app')
@section('title', 'حقائق البيع')
@section('subtitle', 'ما يجوز للمحتوى أن يعد به عميلك — وما لا تكتبه هنا لن يُذكر')

@section('content')

@php
    $freeShipping = old('free_shipping', $facts['free_shipping'] ?? '');
    $channels = (array) old('channels', $facts['channels'] ?? []);
    $payments = (array) old('payments', $facts['payments'] ?? []);
    $offerType = old('type', 'percent');
@endphp

<div class="space-y-5 max-w-4xl">

    <div class="alert-info" role="note">
        <x-icon name="info" class="w-5 h-5 shrink-0 mt-px" />
        <p class="leading-relaxed">
            كل منشور نولّده نفحصه قبل أن يصلك: إن ذكر توصيلاً أو خصماً أو كوداً أو تقييماً أو رأي عميل
            لا يوجد هنا أو في بيانات منتجاتك، نطلب تصحيحه. ما تكتبه هنا يصير مسموحاً بنصه.
        </p>
    </div>

    {{-- ================= الشحن والدفع ================= --}}
    <x-section icon="package" title="التوصيل والدفع" description="حقائق عامة عن متجرك تنطبق على كل المنتجات. اترك الحقل فارغاً إن لم ينطبق.">
        <form method="POST" action="{{ route('store.facts.update') }}" class="space-y-4" x-data="{ free: @js($freeShipping) }">
            @csrf
            @method('PUT')

            <x-field label="التوصيل والشحن" name="delivery" optional hint="كما تقوله لعميل يسألك: أين توصّلون، وخلال كم.">
                <input
                    id="delivery" name="delivery" type="text" maxlength="300"
                    class="field @error('delivery') field-invalid @enderror"
                    value="{{ old('delivery', $facts['delivery'] ?? '') }}"
                    placeholder="مثال: توصيل داخل الرياض خلال يوم، وشحن لبقية المدن خلال 2 إلى 4 أيام"
                >
            </x-field>

            <div class="grid sm:grid-cols-[1fr_12rem] gap-4 items-start">
                <x-field label="الشحن المجاني" name="free_shipping">
                    <select id="free_shipping" name="free_shipping" class="field" x-model="free">
                        <option value="">لا يوجد</option>
                        <option value="always" @selected($freeShipping === 'always')>لكل الطلبات</option>
                        <option value="over" @selected($freeShipping === 'over')>للطلبات فوق مبلغ معيّن</option>
                    </select>
                </x-field>

                <x-field label="فوق (ريال)" name="free_shipping_over" x-show="free === 'over'" x-cloak>
                    <input
                        id="free_shipping_over" name="free_shipping_over" type="number" min="1" step="1"
                        inputmode="numeric" dir="ltr"
                        class="field tnum @error('free_shipping_over') field-invalid @enderror"
                        value="{{ old('free_shipping_over', $facts['free_shipping_over'] ?? '') }}"
                        placeholder="200"
                        :disabled="free !== 'over'"
                    >
                </x-field>
            </div>

            <x-field label="الفروع" name="branches" optional>
                <input
                    id="branches" name="branches" type="text" maxlength="300"
                    class="field @error('branches') field-invalid @enderror"
                    value="{{ old('branches', $facts['branches'] ?? '') }}"
                    placeholder="مثال: ثلاثة فروع في الرياض وجدة"
                >
            </x-field>

            <fieldset>
                <legend class="label">البيع</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (['retail' => 'بالتجزئة', 'wholesale' => 'بالجملة'] as $value => $label)
                        <label class="choice items-center py-2">
                            <input
                                type="checkbox" name="channels[]" value="{{ $value }}" @checked(in_array($value, $channels, true))
                                class="w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500"
                            >
                            <span class="text-sm text-fg">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset>
                <legend class="label">طرق الدفع</legend>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    @foreach (\App\Models\Brand::PAYMENT_METHODS as $value => $label)
                        <label class="choice items-center py-2">
                            <input
                                type="checkbox" name="payments[]" value="{{ $value }}" @checked(in_array($value, $payments, true))
                                class="w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500"
                            >
                            <span class="text-sm text-fg">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <x-field label="الاسترجاع والاستبدال" name="returns" optional hint="اكتب ما تقدمه فعلاً. إن لم يوجد، اتركه فارغاً بدل أن تكتب «لا يوجد».">
                <input
                    id="returns" name="returns" type="text" maxlength="300"
                    class="field @error('returns') field-invalid @enderror"
                    value="{{ old('returns', $facts['returns'] ?? '') }}"
                    placeholder="مثال: استبدال خلال 7 أيام للمنتجات غير المفتوحة"
                >
            </x-field>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary"><span>حفظ</span></button>
            </div>
        </form>
    </x-section>

    {{-- ================= العروض ================= --}}
    <x-section icon="coins" title="العروض والأكواد" description="يذكر المحتوى العرض ما دام سارياً يوم التوليد، ويتوقف عنه تلقائياً بعد انتهائه.">
        @if ($offers->isNotEmpty())
            <ul class="divide-y divide-line -mt-1 mb-5">
                @foreach ($offers as $offer)
                    @php $running = $offer->isRunningOn(today()); @endphp
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-fg">{{ $offer->title }}</p>
                            <p class="text-xs text-fg-muted mt-0.5">{{ $offer->promptLine() }}</p>
                            <p class="text-xs text-fg-subtle mt-0.5">
                                {{ $offer->product ? 'على: '.$offer->product->title : 'على المتجر كله' }}
                            </p>
                        </div>

                        @if (! $offer->is_active)
                            <span class="chip-quiet">موقوف</span>
                        @elseif ($running)
                            <span class="chip-success">سارٍ اليوم</span>
                        @elseif ($offer->ends_at && $offer->ends_at->lt(today()))
                            <span class="chip-neutral">انتهى</span>
                        @else
                            <span class="chip-info">يبدأ {{ $offer->starts_at?->locale('ar')->translatedFormat('j F') }}</span>
                        @endif

                        <form method="POST" action="{{ route('store.offers.toggle', $offer) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn-ghost btn-sm"><span>{{ $offer->is_active ? 'إيقاف' : 'تفعيل' }}</span></button>
                        </form>

                        <x-confirm
                            :action="route('store.offers.destroy', $offer)"
                            title="حذف هذا العرض؟"
                            message="لن يذكره أي محتوى جديد. المنشورات التي كُتبت به تبقى كما هي."
                        />
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('store.offers.store') }}" class="space-y-4 rounded-xl bg-muted/50 p-4" x-data="{ type: @js($offerType) }">
            @csrf
            <p class="text-sm font-semibold text-fg">عرض جديد</p>

            <div class="grid sm:grid-cols-2 gap-4">
                <x-field label="اسم العرض" name="title" required>
                    <input id="title" name="title" type="text" required maxlength="160" class="field @error('title') field-invalid @enderror"
                           value="{{ old('title') }}" placeholder="مثال: خصم اليوم الوطني">
                </x-field>

                <x-field label="النوع" name="type">
                    <select id="type" name="type" class="field" x-model="type">
                        @foreach (\App\Models\Offer::TYPES as $value => $label)
                            <option value="{{ $value }}" @selected($offerType === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="القيمة" name="value" x-show="type === 'percent' || type === 'amount'">
                    <div class="relative">
                        <input id="value" name="value" type="number" min="0.01" step="0.01" inputmode="decimal" dir="ltr"
                               class="field tnum pe-14 @error('value') field-invalid @enderror" value="{{ old('value') }}"
                               :disabled="! (type === 'percent' || type === 'amount')">
                        <span class="absolute inset-y-0 end-0 grid place-items-center w-12 text-xs text-fg-subtle" x-text="type === 'percent' ? '%' : 'ريال'"></span>
                    </div>
                </x-field>

                <x-field label="الكود" name="coupon_code" optional hint="حروف إنجليزية وأرقام، كما يكتبه العميل عند الدفع.">
                    <input id="coupon_code" name="coupon_code" type="text" maxlength="40" dir="ltr"
                           class="field uppercase @error('coupon_code') field-invalid @enderror" value="{{ old('coupon_code') }}" placeholder="KSA96">
                </x-field>

                <x-field label="يبدأ" name="starts_at" optional>
                    <input id="starts_at" name="starts_at" type="date" dir="ltr" class="field tnum @error('starts_at') field-invalid @enderror" value="{{ old('starts_at') }}">
                </x-field>

                <x-field label="ينتهي" name="ends_at" optional hint="بعده لا يُذكر العرض في أي منشور جديد.">
                    <input id="ends_at" name="ends_at" type="date" dir="ltr" class="field tnum @error('ends_at') field-invalid @enderror" value="{{ old('ends_at') }}">
                </x-field>

                <x-field label="على منتج بعينه" name="product_id" optional>
                    <select id="product_id" name="product_id" class="field">
                        <option value="">المتجر كله</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>{{ $product->title }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="الشروط" name="conditions" optional>
                    <input id="conditions" name="conditions" type="text" maxlength="300" class="field @error('conditions') field-invalid @enderror"
                           value="{{ old('conditions') }}" placeholder="مثال: للطلبات فوق 200 ريال">
                </x-field>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary"><span>إضافة العرض</span></button>
            </div>
        </form>
    </x-section>

    {{-- ================= تجارب العملاء ================= --}}
    <x-section icon="users" title="تجارب العملاء" description="كلام عملاء حقيقيين بإذنهم. يُقتبس بنصه في قالب «دليل اجتماعي»، ولا يُعاد صياغته.">
        @if ($testimonials->isNotEmpty())
            <ul class="divide-y divide-line -mt-1 mb-5">
                @foreach ($testimonials as $testimonial)
                    <li class="flex items-start gap-3 py-3">
                        <blockquote class="min-w-0 flex-1">
                            <p class="text-sm text-fg leading-relaxed">«{{ $testimonial->body }}»</p>
                            <footer class="text-xs text-fg-muted mt-1">
                                — {{ $testimonial->displayName() }}
                                @if ($testimonial->rating) · {{ $testimonial->rating }} من 5 @endif
                                @if ($testimonial->source) · {{ $testimonial->source }} @endif
                                · {{ $testimonial->product?->title ?? 'عن المتجر' }}
                            </footer>
                        </blockquote>

                        <x-confirm
                            :action="route('store.testimonials.destroy', $testimonial)"
                            title="حذف هذه التجربة؟"
                            message="لن تُقتبس في أي محتوى جديد."
                        />
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('store.testimonials.store') }}" class="space-y-4 rounded-xl bg-muted/50 p-4">
            @csrf
            <p class="text-sm font-semibold text-fg">تجربة جديدة</p>

            <x-field label="ماذا قال العميل؟" name="body" required hint="بنصه كما كتبه، دون تحسين: سيُقتبس حرفياً.">
                <textarea id="body" name="body" rows="3" required minlength="10" maxlength="600"
                          class="field @error('body') field-invalid @enderror"
                          placeholder="مثال: الطحن مضبوط على الفي ستي، والطلب وصل ثاني يوم">{{ old('body') }}</textarea>
            </x-field>

            <div class="grid sm:grid-cols-2 gap-4">
                <x-field label="اسم العميل" name="author_name" required>
                    <input id="author_name" name="author_name" type="text" required maxlength="80"
                           class="field @error('author_name') field-invalid @enderror" value="{{ old('author_name') }}">
                </x-field>

                <x-field label="يُعرض الاسم" name="display_as">
                    <select id="display_as" name="display_as" class="field">
                        @foreach (\App\Models\Testimonial::DISPLAY as $value => $label)
                            <option value="{{ $value }}" @selected(old('display_as', 'first_name') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="تقييمه" name="rating" optional>
                    <select id="rating" name="rating" class="field">
                        <option value="">بلا تقييم</option>
                        @for ($stars = 5; $stars >= 1; $stars--)
                            <option value="{{ $stars }}" @selected((string) old('rating') === (string) $stars)>{{ $stars }} من 5</option>
                        @endfor
                    </select>
                </x-field>

                <x-field label="عن منتج بعينه" name="product_id" for="testimonial_product" optional>
                    <select id="testimonial_product" name="product_id" class="field">
                        <option value="">عن المتجر عموماً</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->title }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="المصدر" name="source" optional class="sm:col-span-2">
                    <input id="source" name="source" type="text" maxlength="60" class="field" value="{{ old('source') }}"
                           placeholder="مثال: تقييم في المتجر، رسالة واتساب">
                </x-field>
            </div>

            <label class="choice items-start">
                <input type="checkbox" name="consent" value="1" required @checked(old('consent'))
                       class="mt-0.5 w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500">
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-fg">لدي إذن العميل بنشر تجربته</span>
                    <span class="block text-xs text-fg-muted mt-0.5 leading-relaxed">
                        نشر كلام شخص واسمه دون إذنه مسؤولية عليك. «الاسم الأول فقط» أو «بلا اسم» يقللان ما تحتاج إذناً به.
                    </span>
                </span>
            </label>
            @error('consent')
                <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
            @enderror

            <div class="flex justify-end">
                <button type="submit" class="btn-primary"><span>إضافة التجربة</span></button>
            </div>
        </form>
    </x-section>
</div>
@endsection
