@extends('layouts.public')

@section('title', 'من نحن')
@section('description', 'مسوق AI منصة عربية تحوّل هوية متجرك ومنتجاتك إلى محتوى تسويقي واضح ومؤثر.')

@section('content')

@php
    $pillars = [
        ['pen', 'مبنية للمحتوى العربي', 'تفهم الفرق بين لهجات الجمهور العربي والخليجي، وتكتب بنبرة تشبه علامتك لا نصًا عامًا.'],
        ['target', 'تركيز على النتائج لا النصوص فقط', 'الهدف ليس منشورًا جميلًا فقط، بل محتوى يساعد على التأثير والإقناع والبيع.'],
        ['layers', 'منهجية داخل المنصة', 'خطوات صناعة المحتوى تحوّلت إلى أسئلة وتدفقات تقود المستخدم خطوة بخطوة.'],
    ];

    $uses = [
        ['calendar', 'تخطيط المحتوى الشهري', 'خطط محتواك الشهري في وقت أقل', 'بدل أن تبدأ كل يوم من الصفر، ابنِ خطة منظمة لشهر كامل، مرتبطة بنشاطك وجمهورك ومنتجاتك.'],
        ['shopping-bag', 'صناعة محتوى للمتاجر الإلكترونية', 'حوّل منتجاتك إلى محتوى يبيع', 'أضف منتجاتك مرة واحدة، ثم استخدمها لإنشاء منشورات وعروض وأفكار ريلز وتصاميم تعرض المنتج بوضوح.'],
        ['megaphone', 'إدارة محتوى السوشيال ميديا', 'نظّم محتوى منصاتك من مكان واحد', 'سواء كنت تعمل على إنستغرام أو تيك توك أو غيرهما، تكتب بصيغة كل منصة وتنظم الأفكار في تقويم واحد.'],
        ['image', 'صور احترافية بالذكاء الاصطناعي', 'اصنع صورًا تناسب علامتك التجارية', 'استوديو صور يولّد تصاميم وصور منتجات وتركّب عليها شعارك، دون الحاجة إلى مصمم.'],
        ['mic', 'التعليق الصوتي العربي', 'حوّل النص إلى صوت عربي احترافي', 'اكتب نصك واختر راويًا ولهجة، لتحصل على تعليق صوتي واضح لفيديوهاتك وإعلاناتك.'],
        ['file-text', 'أوصاف منتجات متوافقة مع SEO', 'اكتب أوصافًا تساعد العميل ومحركات البحث', 'أوصاف واضحة لمنتجاتك وخدماتك، تجمع بين لغة تسويقية مقنعة وفوائد حقيقية وكلمات مناسبة للبحث.'],
        ['shield-check', 'مراجعة الصدق قبل النشر', 'لا تنشر رقمًا لم تقله', 'تفحص بوابة الصدق كل نص، وتنبّهك إلى أي رقم أو ادعاء أو كود خصم لم تذكره في بياناتك.'],
        ['users', 'خدمة فرق التسويق وصنّاع المحتوى', 'أداة يومية لفريق المحتوى', 'توحّد الأفكار، وتقلل التشتت، وتحوّل العمل اليومي إلى نظام واضح ومنتظم.'],
    ];
@endphp

{{-- ================= الغلاف ================= --}}
<section class="relative overflow-hidden">
    <div class="pointer-events-none absolute inset-0" aria-hidden="true"
         style="background: radial-gradient(50rem 20rem at 50% -20%, rgb(var(--brand-200) / .55), transparent 65%);"></div>
    <div class="relative mx-auto max-w-3xl px-5 pb-16 pt-16 text-center">
        <span class="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3.5 py-1.5 text-xs font-semibold text-brand-700 dark:border-brand-800 dark:bg-brand-900/30 dark:text-brand-300">
            من نحن <x-icon name="sparkles" class="h-3.5 w-3.5" />
        </span>
        <h1 class="mt-6 text-4xl font-bold leading-[1.4] sm:text-5xl">
            خبرة حقيقية في صناعة<br><span class="text-brand-600 dark:text-brand-400">المحتوى العربي</span>
        </h1>
        <p class="mt-5 text-fg-muted">من خبرة صنّاع المحتوى… إلى منصة تخدمك يوميًا.</p>
    </div>
</section>

{{-- ================= القصة ================= --}}
<section class="pb-20">
    <div class="mx-auto grid max-w-6xl gap-10 px-5 lg:grid-cols-[1fr_1.2fr] lg:items-start">
        <div class="space-y-4 lg:order-2">
            <p class="leading-loose text-fg-muted">
                وراء مسوق AI فكرة بسيطة: أن الكتابة التسويقية الجيدة تبدأ من فهم علامتك وجمهورك، لا من نص عام يصلح لأي متجر.
                لذلك بنينا منصة تسأل قبل أن تكتب، وتعرف منتجك قبل أن تصفه.
            </p>
            <p class="leading-loose text-fg-muted">
                جمعنا في المنصة خطوات صناعة المحتوى كما تُمارَس فعلًا: بناء الأفكار، وصياغة الرسائل، وفهم الجمهور،
                وتحويل المنتجات والخدمات إلى محتوى يلفت الانتباه ويقود للقرار.
            </p>
            <p class="leading-loose text-fg-muted">
                ولأن الثقة أهم من الكلام اللامع، وضعنا بوابة صدق تفحص كل نص وتمنع الأرقام والوعود التي لم تذكرها أنت.
            </p>
            <p class="font-medium leading-loose">
                هدفنا أن تحصل على نتائج عملية: محتوى أوضح، أفكار أقوى، رسائل أكثر إقناعًا،
                وتصاميم تساعد علامتك التجارية على الظهور بثقة والتأثير والبيع فعلًا.
            </p>
        </div>

        <div class="space-y-3 lg:order-1">
            @foreach ($pillars as [$icon, $title, $text])
                <article class="card flex items-start gap-4 p-5">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300"><x-icon :name="$icon" class="h-5 w-5" /></span>
                    <div>
                        <h2 class="text-[15px] font-bold">{{ $title }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-fg-muted">{{ $text }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= الاستخدامات ================= --}}
<section class="bg-muted/60 py-20">
    <div class="mx-auto max-w-6xl px-5">
        <div class="mx-auto max-w-2xl text-center">
            <span class="inline-flex rounded-full border border-brand-200 bg-brand-50 px-3.5 py-1.5 text-xs font-semibold text-brand-700 dark:border-brand-800 dark:bg-brand-900/30 dark:text-brand-300">استخدامات عملية</span>
            <h2 class="mt-4 text-3xl font-bold sm:text-4xl">ماذا يمكنك أن تنجز مع <span class="text-brand-600 dark:text-brand-400">مسوق AI؟</span></h2>
            <p class="mt-3 text-fg-muted">طرق عملية تساعدك على صناعة محتوى عربي أوضح وأسرع وأكثر تأثيرًا، من التخطيط حتى النشر.</p>
        </div>

        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($uses as [$icon, $title, $tag, $text])
                <article class="card p-5">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300"><x-icon :name="$icon" class="h-5 w-5" /></span>
                    <h3 class="mt-4 font-bold leading-snug">{{ $title }}</h3>
                    <p class="mt-1 text-sm font-medium text-brand-600 dark:text-brand-400">{{ $tag }}</p>
                    <p class="mt-3 text-sm leading-relaxed text-fg-muted">{{ $text }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= الدعوة ================= --}}
<section class="px-5 py-20">
    <div class="mx-auto max-w-2xl rounded-3xl border-2 border-brand-500 bg-card px-6 py-12 text-center">
        <h2 class="text-2xl font-bold sm:text-3xl">جاهز لتصنع محتوى يصنع الفرق؟</h2>
        <p class="mt-3 text-fg-muted">ابدأ اليوم واستفد من خبرة صنّاع المحتوى العربي، مبنية داخل منصة مسوق AI.</p>
        <div class="mt-7 flex flex-wrap justify-center gap-3">
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg">ابدأ الآن <x-icon name="arrow-left" class="h-4 w-4" /></a>
            <a href="{{ route('home') }}" class="btn btn-secondary btn-lg">العودة للرئيسية</a>
        </div>
    </div>
</section>

@endsection
