@extends('layouts.guest')

@use('App\Support\Offer')

@section('title', 'إنشاء حساب')
@section('heading', 'أنشئ حسابك وابدأ خلال دقيقة')

@section('switch')
    <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 text-lg font-semibold text-primary-ink hover:underline underline-offset-4">
        <x-public.icon name="login" class="h-5 w-5" />
        لدي حساب بالفعل
    </a>
@endsection

@section('content')
@php
    $input = 'block h-12 w-full rounded-xl border bg-gray-50 px-4 text-[15px] text-gray-900 placeholder:text-gray-400 transition focus:bg-white focus:outline-none focus:ring-4 focus:ring-primary/10 focus:ring-offset-0';
    $ok = 'border-gray-200 focus:border-primary';
    $bad = 'border-red-400 focus:border-red-500';
    $label = 'mb-2 flex items-center gap-2 text-sm font-medium text-gray-700';

    $plan = old('plan', request('plan'));
    $plan = in_array($plan, ['monthly', 'yearly'], true) ? $plan : 'monthly';
    $trial = (bool) old('trial', request('trial'));
@endphp

{{-- الباقة والتجربة وكود الخصم للعرض حتى يُربط الدفع؛ الخادم يتجاهلها الآن --}}
<form method="POST" action="{{ route('register') }}" class="space-y-5" novalidate
      x-data="{ plan: '{{ $plan }}', trial: {{ $trial ? 'true' : 'false' }}, agreed: {{ old('terms') ? 'true' : 'false' }} }">
    @csrf

    <div>
        <label for="name" class="{{ $label }}"><x-public.icon name="user" class="h-4 w-4 text-gray-400" /> الاسم</label>
        <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="أدخل اسمك"
               class="{{ $input }} @error('name') {{ $bad }} @else {{ $ok }} @enderror"
               autocomplete="name" required autofocus
               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        @error('name') <p id="name-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="email" class="{{ $label }}"><x-public.icon name="mail" class="h-4 w-4 text-gray-400" /> البريد الإلكتروني</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="example@email.com" dir="ltr"
               class="{{ $input }} text-left @error('email') {{ $bad }} @else {{ $ok }} @enderror"
               autocomplete="email" inputmode="email" required
               @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email') <p id="email-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="password" class="{{ $label }}"><x-public.icon name="lock" class="h-4 w-4 text-gray-400" /> كلمة السر</label>
        <div class="relative" x-data="{ show: false }">
            <input id="password" name="password" type="password" :type="show ? 'text' : 'password'"
                   placeholder="أدخل كلمة السر (8 أحرف على الأقل)"
                   class="{{ $input }} pe-12 @error('password') {{ $bad }} @else {{ $ok }} @enderror"
                   minlength="8" autocomplete="new-password" required
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            <button type="button" @click="show = !show" :aria-pressed="show.toString()"
                    class="absolute inset-y-0 end-0 grid w-12 place-items-center rounded-xl text-gray-400 transition-colors hover:text-gray-600">
                <x-public.icon name="eye" class="h-5 w-5" />
                <span class="sr-only">إظهار كلمة السر</span>
            </button>
        </div>
        @error('password') <p id="password-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <fieldset>
        <legend class="{{ $label }}"><x-public.icon name="sparkles" class="h-4 w-4 text-primary" /> اختر الباقة</legend>
        <div class="flex gap-3 pt-1">
            @foreach ([
                'monthly' => [Offer::monthly(), 'شهر'],
                'yearly' => [Offer::yearly(), 'سنة'],
            ] as $key => [$price, $unit])
                <label class="relative flex h-[5.5rem] w-28 cursor-pointer flex-col items-center justify-center rounded-xl border-2 text-center transition-colors"
                       :class="plan === '{{ $key }}' ? 'border-primary bg-amber-50/70' : 'border-gray-200 bg-white hover:border-gray-300'">
                    <input type="radio" name="plan" value="{{ $key }}" x-model="plan" class="peer sr-only">
                    <span class="pointer-events-none absolute -inset-px rounded-xl peer-focus-visible:ring-2 peer-focus-visible:ring-primary peer-focus-visible:ring-offset-2" aria-hidden="true"></span>

                    @if ($key === 'yearly')
                        <span class="absolute -top-2.5 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-primary-strong px-2.5 py-0.5 text-[11px] font-bold text-white">الأفضل</span>
                    @endif
                    <span x-show="plan === '{{ $key }}'" x-cloak class="absolute right-1.5 top-1.5 grid h-5 w-5 place-items-center rounded-full bg-primary text-white" aria-hidden="true">
                        <x-public.icon name="check" class="h-3 w-3" stroke="3" />
                    </span>

                    <span class="text-base font-bold text-gray-900">{{ config("marketing.plans.$key.short") }}</span>
                    <span class="text-lg font-bold leading-tight text-primary-strong">{{ $price }}</span>
                    <span class="text-xs text-gray-500">/{{ $unit }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <label class="flex cursor-pointer items-center gap-3 rounded-xl border-2 border-dashed px-4 py-4 transition-colors"
           :class="trial ? 'border-primary bg-amber-50/60' : 'border-gray-300 bg-white hover:border-gray-400'">
        <input type="checkbox" name="trial" value="1" x-model="trial"
               class="h-5 w-5 shrink-0 rounded-full border-gray-300 text-primary focus:ring-primary">
        <span class="flex-1">
            <span class="block font-bold text-gray-900">{{ Offer::trialLabel() }} فقط</span>
            <span class="mt-0.5 block text-xs text-gray-500">بعدها يبدأ اشتراكك تلقائيًا • يمكنك الإلغاء في أي وقت</span>
        </span>
    </label>

    <div>
        <label for="coupon" class="{{ $label }}"><x-public.icon name="tag" class="h-4 w-4 text-gray-400" /> كود الخصم (اختياري)</label>
        <input id="coupon" name="coupon" type="text" value="{{ old('coupon') }}" placeholder="أدخل كود الخصم"
               class="{{ $input }} {{ $ok }} text-center" autocomplete="off">
    </div>

    <div>
        <label class="flex cursor-pointer items-start gap-3 text-sm leading-relaxed text-gray-700">
            <input type="checkbox" name="terms" value="1" x-model="agreed" required
                   class="mt-0.5 h-5 w-5 shrink-0 rounded border-gray-300 text-primary focus:ring-primary"
                   @error('terms') aria-invalid="true" aria-describedby="terms-error" @enderror>
            <span>
                أوافق على
                <a href="{{ route('privacy') }}" target="_blank" class="font-medium text-primary-ink hover:underline">سياسة الخصوصية</a>
                و
                <a href="{{ route('refund') }}" target="_blank" class="font-medium text-primary-ink hover:underline">سياسة الاسترجاع والإلغاء</a>
            </span>
        </label>
        @error('terms') <p id="terms-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <button type="submit" :disabled="!agreed"
            class="w-full rounded-xl bg-primary py-3.5 text-[1.1875rem] font-bold text-white shadow-sm transition-colors hover:bg-primary-hover disabled:cursor-not-allowed disabled:bg-primary/45 disabled:shadow-none">
        إنشاء الحساب
    </button>
</form>

<x-public.google-button />
@endsection
