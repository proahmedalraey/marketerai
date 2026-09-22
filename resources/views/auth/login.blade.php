@extends('layouts.guest')
@section('title', 'تسجيل الدخول')
@section('heading', 'تسجيل الدخول')
@section('lede', 'أكمل من حيث توقفت — خطتك ومحتواك محفوظان.')

@section('content')
<form method="POST" action="{{ route('login') }}" class="space-y-4">
    @csrf

    <x-field label="البريد الإلكتروني" name="email" required>
        <div class="relative">
            <span class="absolute inset-y-0 start-0 grid place-items-center w-11 text-fg-subtle pointer-events-none">
                <x-icon name="mail" class="w-[18px] h-[18px]" />
            </span>
            <input
                id="email" name="email" type="email"
                class="field ps-11 @error('email') field-invalid @enderror"
                value="{{ old('email') }}"
                dir="ltr"
                autocomplete="email"
                inputmode="email"
                required autofocus
                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
            >
        </div>
    </x-field>

    <x-field label="كلمة المرور" name="password" required>
        <div class="relative" x-data="{ visible: false }">
            <span class="absolute inset-y-0 start-0 grid place-items-center w-11 text-fg-subtle pointer-events-none">
                <x-icon name="lock" class="w-[18px] h-[18px]" />
            </span>

            <input
                id="password" name="password"
                :type="visible ? 'text' : 'password'"
                type="password"
                class="field ps-11 pe-11 @error('password') field-invalid @enderror"
                dir="ltr"
                autocomplete="current-password"
                required
            >

            {{-- إظهار كلمة المرور يقلّل أخطاء الإدخال على الجوال أكثر من أي رسالة خطأ --}}
            <button
                type="button"
                @click="visible = !visible"
                class="absolute inset-y-0 end-0 grid place-items-center w-11 text-fg-subtle hover:text-fg rounded-xl"
                :aria-pressed="visible ? 'true' : 'false'"
            >
                <x-icon name="eye" class="w-[18px] h-[18px]" />
                <span class="sr-only">إظهار كلمة المرور</span>
            </button>
        </div>
    </x-field>

    <label class="flex items-center gap-2.5 text-sm text-fg-muted cursor-pointer w-fit py-1">
        <input
            type="checkbox" name="remember"
            class="w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500"
        >
        <span>أبقني مسجلاً</span>
    </label>

    <button type="submit" class="btn-primary w-full btn-lg">
        <span>دخول</span>
    </button>

    <p class="text-center text-sm text-fg-muted pt-2">
        ما عندك حساب؟
        <a href="{{ route('register') }}" class="font-semibold text-brand-700 dark:text-brand-400 hover:underline underline-offset-4">
            أنشئ حساباً جديداً
        </a>
    </p>
</form>
@endsection
