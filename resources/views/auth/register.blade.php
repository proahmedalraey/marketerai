@extends('layouts.guest')
@section('title', 'حساب جديد')
@section('heading', 'أنشئ حسابك')
@section('lede', 'دقيقة واحدة للتسجيل، ثم نبني هوية علامتك معاً.')

@section('content')
<form method="POST" action="{{ route('register') }}" class="space-y-4">
    @csrf

    <x-field label="الاسم" name="name" required>
        <input
            id="name" name="name" type="text"
            class="field @error('name') field-invalid @enderror"
            value="{{ old('name') }}"
            autocomplete="name"
            required autofocus
            @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
        >
    </x-field>

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
                required
                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
            >
        </div>
    </x-field>

    {{-- الشرط يُعرض قبل المحاولة لا بعد الفشل --}}
    <x-field label="كلمة المرور" name="password" required hint="٨ أحرف على الأقل.">
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
                minlength="8"
                autocomplete="new-password"
                required
                @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
            >

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

    <x-field label="تأكيد كلمة المرور" name="password_confirmation" required>
        <div class="relative">
            <span class="absolute inset-y-0 start-0 grid place-items-center w-11 text-fg-subtle pointer-events-none">
                <x-icon name="lock" class="w-[18px] h-[18px]" />
            </span>
            <input
                id="password_confirmation" name="password_confirmation" type="password"
                class="field ps-11"
                dir="ltr"
                autocomplete="new-password"
                required
            >
        </div>
    </x-field>

    <button type="submit" class="btn-primary w-full btn-lg">
        <span>إنشاء الحساب</span>
    </button>

    <p class="text-center text-sm text-fg-muted pt-2">
        عندك حساب؟
        <a href="{{ route('login') }}" class="font-semibold text-brand-700 dark:text-brand-400 hover:underline underline-offset-4">
            سجّل الدخول
        </a>
    </p>
</form>
@endsection
