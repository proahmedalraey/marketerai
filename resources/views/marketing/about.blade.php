@extends('layouts.public')

@section('title', 'من نحن')
@section('description', 'مسوق AI منصة عربية تحوّل هوية متجرك ومنتجاتك إلى محتوى تسويقي واضح ومقنع وصادق.')

@section('content')

@php
    $pillars = [
        ['pen', 'خبرة في المحتوى العربي', 'نفهم كيف يتفاعل الجمهور العربي والخليجي، ونكتب بلهجته لا بلغة مترجمة.'],
        ['target', 'النتيجة قبل الكلمات', 'لا يكفي أن يكون المنشور جميلًا؛ المهم أن يقنع ويقود إلى قرار الشراء.'],
        ['layers', 'منهجية داخل المنصة', 'خطوات صناعة المحتوى صارت أسئلة وقوالب وتدفقات ترافقك خطوة بخطوة.'],
    ];

    $uses = [
        ['calendar', 'خطة محتوى لشهر كامل', 'وداعًا لسؤال: ماذا أنشر اليوم؟', 'ولّد أفكارًا مترابطة لشهر كامل من نشاطك ومنتجاتك وجمهورك، ورتّبها على تقويم واحد تصدّره متى شئت.'],
        ['shopping-bag', 'محتوى المتاجر الإلكترونية', 'اجعل كل منتج قصة تُباع', 'أضف منتجاتك مرة واحدة أو استوردها من متجرك، ثم حوّلها إلى منشورات وكاروسيل وسكربتات ريلز تعرضها بوضوح.'],
        ['share', 'إدارة محتوى السوشيال ميديا', '8 منصات بأسلوب كل منصة', 'من إنستغرام وتيك توك إلى لينكدإن وسناب شات، يكتب مسوق AI بطول كل منصة وأسلوبها وعدد هاشتاقاتها المناسب.'],
        ['wand', 'صور منتجات احترافية', 'استوديو صور بلا مصمم', 'ولّد صور منتجاتك بدقة تصل إلى 4K، وأزل الخلفية، ودع المنصة تركّب شعارك على كل صورة.'],
        ['layers', 'كاروسيل بهوية علامتك', 'شرائح جاهزة تعدّلها كما تريد', 'محرر شرائح يطبّق ألوان علامتك، وتعيد فيه كتابة أي شريحة بتوجيه منك دون أن تبدأ من الصفر.'],
        ['mic', 'تعليق صوتي عربي', 'صوت احترافي بلهجة جمهورك', 'حوّل نصوصك إلى تعليق صوتي بلهجات عربية متعددة، واختر من بين 18 راويًا لإعلاناتك وفيديوهاتك.'],
        ['calendar-plus', 'المناسبات والمواسم', 'اربط منتجك بالموسم بذكاء', 'قالب مخصص للمناسبات يربط منتجك بالموسم ربطًا طبيعيًا، بلا مبالغة ولا عبارات مستهلكة.'],
        ['users', 'فرق التسويق وصنّاع المحتوى', 'نبرة واحدة لكل ما تنشرونه', 'وحّدوا صوت العلامة في كل منشور، وحوّلوا الأفكار المتفرقة إلى خطة واضحة يعمل عليها الجميع.'],
    ];
@endphp

{{-- ================= الغلاف ================= --}}
<section class="relative isolate overflow-hidden bg-gradient-to-b from-amber-50 to-white pb-20 pt-32 text-center">
    <div class="absolute left-1/2 top-24 -z-10 h-40 w-[36rem] -translate-x-1/2 rounded-full bg-amber-100/60 blur-3xl" aria-hidden="true"></div>

    <div class="mx-auto max-w-3xl px-4">
        <span class="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-100 px-4 py-1.5 text-sm font-semibold text-amber-800">
            من نحن
            <x-icon name="sparkles" class="h-4 w-4 text-primary" />
        </span>
        <h1 class="mt-6 text-4xl font-bold leading-tight text-gray-900 md:text-5xl">
            منصة عربية تفهم<br>
            <span class="text-primary">لغة عملائك</span>
        </h1>
        <p class="mt-5 text-lg text-gray-600">بدأنا من سؤال يعرفه كل تاجر… وبنينا له إجابة تعمل معك كل يوم.</p>
    </div>
</section>

{{-- ================= القصة ================= --}}
<section class="pb-24 pt-8">
    <div class="mx-auto grid max-w-6xl gap-12 px-4 md:px-6 lg:grid-cols-[1.45fr_1fr] lg:items-center">
        <div class="space-y-5 text-[1.05rem] leading-8 text-gray-600">
            <p>بدأ مسوق AI من أسئلة يعرفها كل صاحب متجر: ماذا أنشر اليوم؟ كيف أكتب عن منتج مكرر بطريقة جديدة؟ ولماذا لا يشبه المحتوى المولَّد صوت علامتي؟</p>
            <p>حوّلنا إجابات هذه الأسئلة إلى منهجية داخل المنصة: فهم العلامة وجمهورها، وبناء الزاوية التسويقية، وصياغة رسالة تناسب كل منصة، وتحويل المنتج إلى قصة تقود إلى قرار الشراء.</p>
            <p>كل ما يكتبه مسوق AI يبدأ من بياناتك أنت: نبرتك ولهجتك ومنتجاتك وعروضك الحقيقية، لا من قوالب عامة تتكرر عند الجميع. ولأن الثقة رأس مال أي علامة، يمر كل نص على بوابة صدق تمنع أي رقم أو وعد لم تذكره.</p>
            <p class="font-semibold text-gray-800">هدفنا نتائج عملية تراها في حسابك: محتوى أوضح، وأفكار أقوى، ورسائل أصدق، وحضور يليق بعلامتك التجارية.</p>
        </div>

        <div class="space-y-4">
            @foreach ($pillars as [$icon, $title, $text])
                <article class="flex items-start gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-amber-50 text-primary">
                        <x-icon :name="$icon" class="h-5 w-5" />
                    </span>
                    <div>
                        <h2 class="font-bold text-gray-900">{{ $title }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-gray-500">{{ $text }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= الاستخدامات ================= --}}
<section class="bg-gray-50/70 py-24">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-14 text-center">
            <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-bold text-amber-800">استخدامات عملية</span>
            <h2 class="mb-4 mt-6 text-3xl font-bold text-gray-900 md:text-5xl">ماذا يمكنك أن تنجز مع <span class="text-primary">مسوق AI؟</span></h2>
            <p class="mx-auto max-w-2xl text-lg text-gray-500">طرق عملية لصناعة محتوى عربي أوضح وأسرع وأكثر تأثيرًا، من التخطيط حتى الجدولة.</p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($uses as [$icon, $title, $tag, $text])
                <article class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm transition-shadow hover:shadow-md">
                    <span class="mb-6 grid h-10 w-10 place-items-center rounded-lg bg-amber-50 text-primary">
                        <x-icon :name="$icon" class="h-5 w-5" />
                    </span>
                    <h3 class="font-bold leading-snug text-gray-900">{{ $title }}</h3>
                    <p class="mt-1 text-sm font-semibold text-primary-ink">{{ $tag }}</p>
                    <p class="mt-3 text-sm leading-relaxed text-gray-500">{{ $text }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= الدعوة ================= --}}
<section class="py-20 md:py-24">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mx-auto max-w-3xl rounded-2xl border-2 border-orange-500 bg-white p-8 text-center md:p-12">
            <h2 class="text-3xl font-bold text-gray-900">جاهز تبدأ محتوى يليق بعلامتك؟</h2>
            <p class="mt-3 text-gray-600">أنشئ حسابك الآن، ودع مسوق AI يكتب بصوت علامتك من اليوم الأول.</p>
            <div class="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                <a href="{{ route('register') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-full bg-primary px-8 py-4 text-[1.1875rem] font-bold text-white shadow-lg shadow-amber-200/50 transition-colors hover:bg-primary-hover sm:w-auto">
                    اشترك الآن
                    <x-icon name="arrow-left" class="h-5 w-5" />
                </a>
                <a href="{{ route('home') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-full border border-gray-300 bg-white px-8 py-4 text-lg font-semibold text-gray-700 transition-colors hover:bg-gray-50 sm:w-auto">
                    <x-icon name="arrow-right" class="h-5 w-5" />
                    العودة للرئيسية
                </a>
            </div>
        </div>
    </div>
</section>

@endsection
