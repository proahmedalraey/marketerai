@extends('layouts.guest')

@section('title', 'تسجيل الدخول')
@section('heading', 'أهلًا بعودتك، سجّل دخولك لتكمل')

@section('switch')
    <a href="{{ route('register') }}" class="inline-flex items-center gap-1.5 text-lg font-semibold text-primary-ink hover:underline underline-offset-4">
        <x-public.icon name="user-plus" class="h-5 w-5" />
        ليس لدي حساب
    </a>
@endsection

@section('content')
@php
    $input = 'block h-12 w-full rounded-xl border bg-gray-50 px-4 text-[15px] text-gray-900 placeholder:text-gray-400 transition focus:bg-white focus:outline-none focus:ring-4 focus:ring-primary/10 focus:ring-offset-0';
    $ok = 'border-gray-200 focus:border-primary';
    $bad = 'border-red-400 focus:border-red-500';
    $label = 'mb-2 flex items-center gap-2 text-sm font-medium text-gray-700';
@endphp

<form method="POST" action="{{ route('login') }}" class="space-y-5">
    @csrf

    <div>
        <label for="email" class="{{ $label }}"><x-public.icon name="mail" class="h-4 w-4 text-gray-400" /> البريد الإلكتروني</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="example@email.com" dir="ltr"
               class="{{ $input }} text-left @error('email') {{ $bad }} @else {{ $ok }} @enderror"
               autocomplete="email" inputmode="email" required autofocus
               @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email') <p id="email-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="password" class="{{ $label }}"><x-public.icon name="lock" class="h-4 w-4 text-gray-400" /> كلمة السر</label>
        <div class="relative" x-data="{ show: false }">
            <input id="password" name="password" type="password" :type="show ? 'text' : 'password'"
                   placeholder="أدخل كلمة السر"
                   class="{{ $input }} pe-12 @error('password') {{ $bad }} @else {{ $ok }} @enderror"
                   autocomplete="current-password" required
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            <button type="button" @click="show = !show" :aria-pressed="show.toString()"
                    class="absolute inset-y-0 end-0 grid w-12 place-items-center rounded-xl text-gray-400 transition-colors hover:text-gray-600">
                <x-public.icon name="eye" class="h-5 w-5" />
                <span class="sr-only">إظهار كلمة السر</span>
            </button>
        </div>
        @error('password') <p id="password-error" class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <label class="flex w-fit cursor-pointer items-center gap-2.5 py-1 text-sm text-gray-600">
        <input type="checkbox" name="remember" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
        أبقني مسجلًا
    </label>

    <button type="submit"
            class="w-full rounded-xl bg-primary py-3.5 text-[1.1875rem] font-bold text-white shadow-sm transition-colors hover:bg-primary-hover">
        تسجيل الدخول
    </button>
</form>

<x-public.google-button />
@endsection
