{{--
    منطقة واحدة لكل رسائل النظام. role="status" يجعل قارئ الشاشة يعلنها
    دون أن يقاطع المستخدم، و role="alert" للأخطاء لأنها تستحق المقاطعة.
--}}

@if (session('status'))
    <div
        x-data="{ show: true }"
        x-show="show"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-end="opacity-0"
        class="alert-success motion-safe:animate-fade-up"
        role="status"
    >
        <x-icon name="check-circle" class="w-5 h-5 shrink-0 mt-px" />
        <p class="flex-1 leading-relaxed">{{ session('status') }}</p>

        <button type="button" @click="show = false" class="shrink-0 -m-1 p-1 rounded-lg hover:bg-success/10">
            <x-icon name="close" class="w-4 h-4" />
            <span class="sr-only">إخفاء الرسالة</span>
        </button>
    </div>
@endif

{{-- حُفظ، لكن فيه ما لن يُنفَّذ: نقوله الآن لا بعد أن يلاحظه التاجر في المنشورات --}}
@if ($warnings = (array) session('warnings'))
    <div class="alert-warning motion-safe:animate-fade-up" role="status">
        <x-icon name="alert-circle" class="w-5 h-5 shrink-0 mt-px" />
        <div class="flex-1 min-w-0">
            <p class="font-semibold">لاحظ قبل أن تكمل</p>
            <ul class="mt-1.5 space-y-1 leading-relaxed {{ count($warnings) > 1 ? 'list-disc ps-4' : '' }}">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

@if ($errors->any())
    <div class="alert-danger motion-safe:animate-fade-up" role="alert">
        <x-icon name="alert-circle" class="w-5 h-5 shrink-0 mt-px" />

        <div class="flex-1 min-w-0">
            <p class="font-semibold">
                {{ $errors->count() === 1 ? 'تعذّر إكمال الطلب' : 'تعذّر إكمال الطلب — '.$errors->count().' مشاكل' }}
            </p>

            <ul class="mt-1.5 space-y-1 leading-relaxed {{ $errors->count() > 1 ? 'list-disc ps-4' : '' }}">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
