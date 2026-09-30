@extends('layouts.public')

@section('title', 'محتوى تسويقي عربي يبيع بالذكاء الاصطناعي')

@use('App\Support\Offer')

@section('content')

@php
    $guarantee = config('marketing.guarantee');
    $video = config('marketing.video_url');
    $salesEmail = config('marketing.contact.email');

    $features = [
        ['palette', 'هويتك البصرية', 'ارفع شعارك وألوانك وأنماطك مرة واحدة، فتلتزم بها كل صورة وتصميم تولّده المنصة.', 'identity.jpg'],
        ['database', 'منتجاتك وخدماتك', 'أضفها يدويًا أو استوردها من متجرك، لتصبح مرجعًا يكتب منه مسوق AI بدقة.', 'products.jpg'],
        ['pen', 'كتابة المحتوى', 'منشورات وكاروسيل وستوري وسكربتات ريلز، مكتوبة بأسلوب كل منصة وطولها المناسب.', 'writer.jpg'],
        ['layers', 'محرّر الكاروسيل', 'شرائح بألوان علامتك، تعدّل نصوصها وترتيبها وتعيد كتابة أي شريحة بتوجيه منك.', 'carousel.jpg'],
        ['target', 'صوت علامتك', 'نبرتك ولهجتك وجمهورك في ملف واحد، يرثه كل ما يُكتب باسم علامتك.', 'brand-voice.jpg'],
        ['image', 'استوديو الصور', 'صور منتجات احترافية بدقة تصل إلى 4K، مع إزالة الخلفية وتركيب شعارك تلقائيًا.', 'studio.jpg'],
        ['layout', 'خطة محتوى شهرية', 'رتّب منشوراتك على تقويم الشهر، وصدّر الخطة كملف Excel متى احتجت.', 'plan.jpg'],
        ['mic', 'التعليق الصوتي', 'حوّل نصك إلى صوت عربي بلهجات متعددة، واختر من بين 18 راويًا.', 'voiceover.jpg'],
    ];

    $steps = [
        ['user-plus', 'أنشئ حسابك', 'سجّل ببريدك الإلكتروني وابدأ رحلتك مع صناعة المحتوى خلال دقيقة.'],
        ['clipboard', 'عرّف علامتك', 'أجب عن أسئلة قصيرة عن نشاطك وجمهورك ومنتجاتك، ليتعلم مسوق AI أسلوبك.'],
        ['sparkles', 'استلم محتواك', 'اختر الهدف والمنصة، ويصلك محتوى جاهز للمراجعة والنشر خلال لحظات.'],
        ['calendar', 'رتّب شهرك', 'ضع منشوراتك على تقويم الشهر، وصدّر خطتك كاملة بضغطة زر.'],
        ['shield-check', 'انشر بثقة', 'كل نص يمر على بوابة الصدق، فلا رقم ولا وعد خارج ما أدخلته أنت.'],
    ];

    $audience = [
        ['shopping-cart', 'أصحاب المتاجر الإلكترونية'],
        ['share', 'مسوقو السوشيال ميديا'],
        ['briefcase', 'رواد الأعمال والمستقلون'],
        ['video', 'صنّاع المحتوى'],
    ];

    $faq = [
        ['كم منشورًا أستطيع إنشاءه كل شهر؟', 'يعتمد ذلك على نقاط باقتك: تحصل على '.number_format(config('credits.monthly_allowance')).' نقطة شهريًا، ولكل عملية تكلفة واضحة تظهر قبل التنفيذ. المنشور بنقطة واحدة، والكاروسيل بنقطتين، وتكلفة الصور حسب الدقة والجودة.'],
        ['ما المنصات التي يكتب لها مسوق AI؟', 'ثماني منصات: إنستغرام وتيك توك وتويتر (X) وفيسبوك ولينكدإن ويوتيوب وبنترست وسناب شات، بطول وأسلوب يناسب كل منصة. النشر المباشر غير متاح حاليًا؛ تجدول منشوراتك في خطتك الشهرية وتصدّرها.'],
        ['هل المحتوى الذي أحصل عليه خاص بي؟', 'نعم. يُكتب من هوية علامتك ومنتجاتك أنت، ولك حرية تعديله ونشره كما تشاء.'],
        ['هل أحتاج خبرة تقنية لاستخدام المنصة؟', 'لا. تجيب عن أسئلة بسيطة عن علامتك، ثم تختار الهدف والمنصة، ويصلك المحتوى جاهزًا للمراجعة.'],
        ['هل أستطيع تعديل المحتوى بعد إنشائه؟', 'بالتأكيد. كل نص قابل للتعديل، وفي الكاروسيل تعدّل كل شريحة أو تطلب إعادة كتابتها بتوجيه منك.'],
        ['هل يخترع مسوق AI أرقامًا أو عروضًا؟', 'لا. بوابة الصدق تفحص كل نص بعد كتابته، وتمنع أي رقم أو ادعاء أو كود خصم لم تُدخله في بيانات متجرك.'],
        ['هل أستطيع تغيير باقتي لاحقًا؟', 'نعم، يمكنك الانتقال بين الباقة الشهرية والسنوية متى أردت.'],
        ['كيف أتواصل مع الدعم الفني؟', 'راسلنا عبر قنوات التواصل أسفل الصفحة، ونرد عليك في أقرب وقت.'],
    ];

    // زر أساسي كبير: نص 19px عريض يجعل برتقالي الهوية مقروءًا بمعيار النص الكبير
    $btnPrimary = 'inline-flex w-full items-center justify-center gap-2 rounded-full bg-primary px-8 py-4 text-[1.1875rem] font-bold text-white shadow-lg shadow-amber-200/50 transition-colors hover:bg-primary-hover sm:w-auto';
    $btnOutline = 'inline-flex w-full items-center justify-center gap-2 rounded-full border border-gray-300 bg-white px-8 py-4 text-lg font-semibold text-gray-700 transition-colors hover:bg-gray-50 sm:w-auto';
@endphp

{{-- ================= البطل ================= --}}
<section class="relative isolate overflow-hidden bg-gradient-to-b from-amber-50 to-white pb-20 pt-32">
    <div class="absolute right-0 top-0 -z-10 h-full w-1/3 origin-top translate-x-1/4 skew-x-12 bg-amber-100/50" aria-hidden="true"></div>
    <div class="absolute left-10 top-20 -z-10 h-24 w-24 rounded-full bg-amber-200 opacity-40 blur-3xl" aria-hidden="true"></div>

    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="flex flex-col items-center gap-12 lg:flex-row">

            <div class="space-y-8 text-center lg:w-1/2 lg:text-start">
                <div>
                    <span class="mb-6 inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-100 px-4 py-1.5 text-sm font-semibold text-amber-800">
                        <x-icon name="rocket" class="h-4 w-4 text-primary" />
                        محتوى بهويتك.. يبيع عنك.
                    </span>

                    <h1 class="text-4xl font-bold leading-tight text-gray-900 md:text-5xl lg:text-6xl">
                        منتجاتك تستاهل<br>
                        <span class="relative inline-block text-primary">
                            محتوى يبيعها
                            <svg class="absolute -bottom-1 left-0 -z-10 h-3 w-full text-amber-200" viewBox="0 0 100 12" preserveAspectRatio="none" aria-hidden="true">
                                <path d="M0 8 Q 50 0 100 8" stroke="currentColor" stroke-width="8" fill="none" stroke-linecap="round" />
                            </svg>
                        </span>
                    </h1>

                    <p class="mx-auto mt-6 max-w-2xl whitespace-pre-line text-xl leading-relaxed text-gray-600 lg:mx-0">من صفحة منتج واحدة… إلى خطة محتوى لشهر كامل.
مسوق AI يكتب بصوت علامتك، ويجهّز الصور والتعليق الصوتي، ويتحقق من كل ادعاء قبل أن تنشر.</p>
                </div>

                <div class="flex flex-col items-center justify-center gap-4 sm:flex-row lg:justify-start">
                    <a href="{{ route('register', ['trial' => 1]) }}" class="{{ $btnPrimary }}">
                        {{ Offer::trialLabel() }}
                        <x-icon name="arrow-left" class="h-5 w-5" />
                    </a>
                    <a href="{{ route('login') }}" class="{{ $btnOutline }}">لدي حساب بالفعل</a>
                </div>

                <div class="flex flex-col items-center gap-2 text-sm text-gray-500 lg:items-start">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-primary-ink">فقط {{ Offer::monthly() }} شهريًا</span>
                        @if ($guarantee)
                            <span class="h-1 w-1 rounded-full bg-gray-300" aria-hidden="true"></span>
                            <span>{{ $guarantee['short'] }}</span>
                        @endif
                    </div>
                    @if ($guarantee)
                        <p class="text-xs text-gray-500">{{ $guarantee['note'] }}</p>
                    @endif
                </div>
            </div>

            {{-- مولّد المحتوى يعمل: بطاقة مائلة وشارة عائمة --}}
            <div class="relative w-full lg:w-1/2" aria-hidden="true">
                <div class="relative z-10 mx-auto max-w-xl">
                    <div class="relative rotate-2 rounded-2xl border border-gray-100 bg-white p-2 shadow-2xl transition-transform duration-500 hover:rotate-0">
                        <div class="relative flex aspect-video items-center justify-center overflow-hidden rounded-xl border border-dashed border-gray-200 bg-gray-50">
                            <div class="absolute inset-0 bg-gradient-to-tr from-amber-50 to-white opacity-50"></div>
                            <div class="relative p-8 text-center">
                                <span class="mx-auto mb-4 grid h-16 w-16 place-items-center rounded-full bg-amber-100 text-primary">
                                    <x-icon name="upload" class="h-7 w-7" />
                                </span>
                                <p class="mb-2 text-xl font-bold text-gray-800">مسوّقك الذكي يعمل</p>
                                <p class="text-sm text-gray-500">يجهّز خطة محتوى لشهر كامل…</p>
                                <div class="mt-4 flex justify-center gap-1.5">
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-amber-400"></span>
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-amber-400 [animation-delay:150ms]"></span>
                                    <span class="h-2 w-2 animate-bounce rounded-full bg-amber-400 [animation-delay:300ms]"></span>
                                </div>
                            </div>
                        </div>

                        <div class="absolute -bottom-6 -right-6 rounded-xl border border-gray-100 bg-white p-4 shadow-xl motion-safe:animate-float-y">
                            <div class="flex items-center gap-3">
                                <span class="grid h-10 w-10 place-items-center rounded-lg bg-green-100 text-green-600">
                                    <x-icon name="check-circle" class="h-5 w-5" />
                                </span>
                                <div>
                                    <p class="font-bold text-gray-800">جاهز للنشر</p>
                                    <p class="text-xs font-semibold text-green-700">30 منشورًا لهذا الشهر</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ================= الفيديو التعريفي ================= --}}
<section class="relative isolate overflow-hidden bg-white py-24">
    <div class="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-amber-50/50 via-white to-white" aria-hidden="true"></div>

    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-16 text-center">
            <h2 class="mb-6 text-3xl font-bold text-gray-900 md:text-5xl">شاهد مسوق AI أثناء العمل</h2>
            <p class="mx-auto max-w-2xl text-lg text-gray-500">جولة قصيرة تريك كيف يتحول منتجك إلى منشور وصورة وتعليق صوتي في دقائق.</p>
        </div>

        <div class="relative mx-auto max-w-5xl">
            <div class="relative aspect-video overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-[0_25px_60px_-15px_rgba(0,0,0,0.30),0_10px_30px_-10px_rgba(217,119,6,0.35)]"
                 x-data="{ playing: false }">
                @if ($video)
                    <template x-if="playing">
                        <video src="{{ $video }}" class="absolute inset-0 h-full w-full bg-black object-contain" controls autoplay playsinline></video>
                    </template>
                @endif

                <div x-show="!playing" class="absolute inset-0 grid place-items-center">
                    <div class="-mt-6 flex flex-col items-center gap-2 text-center md:mt-0 md:gap-4">
                        <span class="grid h-12 w-12 place-items-center rounded-2xl bg-primary text-white shadow-xl shadow-primary/30 md:h-24 md:w-24 md:rounded-3xl">
                            <x-icon name="sparkles" class="h-6 w-6 md:h-12 md:w-12" />
                        </span>
                        <span class="text-3xl font-extrabold text-gray-900 md:text-6xl">مسوّق</span>
                        <span class="text-sm font-bold text-primary-ink md:text-xl">المحتوى صار أسرع… وأصدق</span>
                    </div>

                    @if ($video)
                        <button type="button" @click="playing = true" class="group absolute inset-0 grid place-items-center">
                            <span class="grid h-20 w-20 place-items-center rounded-full bg-white text-primary shadow-2xl transition-transform duration-300 group-hover:scale-110">
                                <x-icon name="play" class="h-8 w-8" />
                            </span>
                            <span class="sr-only">تشغيل الفيديو التعريفي</span>
                        </button>
                    @else
                        <span class="absolute bottom-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-gray-900/80 px-4 py-1.5 text-xs font-medium text-white md:bottom-5">الفيديو التعريفي قريبًا</span>
                    @endif
                </div>
            </div>
            <div class="absolute -right-12 -top-12 -z-10 h-24 w-24 rounded-full bg-amber-400/20 blur-2xl" aria-hidden="true"></div>
        </div>
    </div>
</section>

{{-- ================= الحل الشامل ================= --}}
<section class="py-16 md:py-24">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="rounded-2xl border-2 border-orange-500 bg-white p-8 text-center md:p-12">
            <h2 class="mb-6 text-3xl font-bold text-gray-900 md:text-4xl">كل أدوات التسويق في منصة واحدة</h2>
            <p class="mx-auto mb-8 max-w-3xl text-lg leading-relaxed text-gray-600 md:text-xl">
                مقابل <span class="font-bold text-primary-ink">{{ Offer::monthly() }} شهريًا</span> فقط، يصير عندك كاتب محتوى ومصمم ومعلّق صوتي يشتغلون بهوية علامتك.
            </p>
            <a href="{{ route('register') }}" class="{{ $btnPrimary }}">ابدأ مع مسوق AI</a>
        </div>
    </div>
</section>

{{-- ================= المميزات ================= --}}
<section id="features" class="scroll-mt-20 bg-gray-50/50 py-24">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-16 text-center">
            <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-bold text-amber-800">ماذا يشمل اشتراكك؟</span>
            <h2 class="mb-4 mt-6 text-3xl font-bold text-gray-900 md:text-5xl">
                أدواتك كلها
                <span class="relative inline-block text-primary">
                    في مكان واحد
                    <svg class="absolute bottom-0 left-0 -z-10 h-2 w-full text-amber-200/70" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
                        <path d="M0 5 Q 50 0 100 5" stroke="currentColor" stroke-width="6" fill="none" stroke-linecap="round" />
                    </svg>
                </span>
            </h2>
            <p class="mx-auto max-w-2xl text-lg text-gray-500">مجموعة أدوات بالذكاء الاصطناعي ترافقك في رحلة المحتوى، من فكرة المنشور حتى جدولته.</p>
        </div>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
            @foreach ($features as [$icon, $title, $text, $shot])
                <article class="group flex flex-col overflow-hidden rounded-3xl border border-gray-100 bg-white transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-amber-100/50">
                    <div class="relative aspect-video overflow-hidden bg-gray-100">
                        <x-public.shot :src="asset('images/marketing/'.$shot)" :alt="'لقطة من '.$title.' في مسوق AI'" />
                    </div>
                    <div class="flex flex-1 flex-col p-6">
                        <div class="mb-3 flex items-center gap-3">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-amber-50 text-primary transition-colors duration-300 group-hover:bg-primary group-hover:text-white">
                                <x-icon :name="$icon" class="h-5 w-5" />
                            </span>
                            <h3 class="text-lg font-bold leading-tight text-gray-900">{{ $title }}</h3>
                        </div>
                        <p class="text-sm leading-relaxed text-gray-500">{{ $text }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= كيف نعمل ================= --}}
<section id="how" class="scroll-mt-20 overflow-hidden bg-white py-24">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-20 text-center">
            <h2 class="mb-6 text-3xl font-bold text-gray-900 md:text-5xl">كيف يعمل مسوق AI؟</h2>
            <p class="text-lg text-gray-500">خمس خطوات تفصلك عن أول منشور جاهز.</p>
        </div>

        <div class="relative mx-auto max-w-4xl">
            <div class="absolute bottom-0 left-1/2 top-0 hidden w-0.5 -translate-x-1/2 bg-gray-200 md:block" aria-hidden="true"></div>
            <div class="absolute bottom-0 top-0 start-8 w-0.5 bg-gray-200 md:hidden" aria-hidden="true"></div>

            <ol class="space-y-12 md:space-y-24">
                @foreach ($steps as $i => [$icon, $title, $text])
                    @php $flip = $i % 2 === 1; @endphp
                    <li class="relative flex flex-col items-center gap-8 md:flex-row {{ $flip ? 'md:flex-row-reverse' : '' }}">
                        <div class="w-full flex-1 ps-20 text-start md:w-auto {{ $flip ? 'md:pe-12 md:ps-0' : 'md:ps-12 md:text-end' }}">
                            <div class="relative rounded-2xl border border-amber-100 bg-amber-50/50 p-6 transition-shadow hover:shadow-md">
                                {{-- سهم البطاقة يتجه نحو الخط الزمني --}}
                                <span class="absolute top-1/2 hidden h-4 w-4 -translate-y-1/2 rotate-45 border-amber-100 bg-amber-50 md:block {{ $flip ? '-right-2 border-r border-t' : '-left-2 border-b border-l' }}" aria-hidden="true"></span>
                                <h3 class="mb-3 text-xl font-bold text-gray-900">{{ $title }}</h3>
                                <p class="leading-relaxed text-gray-500">{{ $text }}</p>
                            </div>
                        </div>

                        <div class="absolute start-0 z-10 grid h-16 w-16 shrink-0 place-items-center rounded-full border-4 border-amber-500 bg-white shadow-lg shadow-amber-200/50 md:relative md:start-auto">
                            <x-icon :name="$icon" class="h-6 w-6 text-amber-600" />
                            <span class="absolute -left-2 -top-2 grid h-6 w-6 place-items-center rounded-full border-2 border-white bg-gray-900 text-xs font-bold text-white">{{ sprintf('%02d', $i + 1) }}</span>
                        </div>

                        <div class="hidden flex-1 md:block" aria-hidden="true"></div>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
</section>

{{-- ================= الأسعار ================= --}}
<section id="pricing" class="scroll-mt-20 bg-white py-24">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-16 text-center">
            <h2 class="mb-6 text-3xl font-bold text-gray-900 md:text-5xl">اختر الباقة المناسبة لك</h2>
            <p class="text-lg text-gray-500">ابدأ بالشهرية بمرونة كاملة، أو وفّر مع السنوية.</p>
        </div>

        <div class="mx-auto grid max-w-5xl grid-cols-1 gap-8 md:grid-cols-2">
            @foreach (['monthly', 'yearly'] as $key)
                @php
                    $plan = config("marketing.plans.$key");
                    $yearly = $key === 'yearly';
                @endphp
                <article class="relative flex flex-col rounded-2xl bg-white p-8 {{ $yearly ? 'border-2 border-amber-500 shadow-xl' : 'border border-gray-200 transition-colors hover:border-amber-400' }}">
                    @if ($yearly && Offer::yearlySaving() > 0)
                        <span class="absolute left-1/2 top-0 -translate-x-1/2 -translate-y-1/2 rounded-full bg-gray-200 px-4 py-1 text-xs font-bold tracking-wider text-gray-700">{{ Offer::yearlySavingLabel() }}</span>
                    @endif

                    <h3 class="mb-2 text-xl font-bold text-gray-900">{{ $plan['label'] }}</h3>
                    <p class="mb-6 text-sm text-gray-500">{{ $plan['hint'] }}</p>

                    <div class="mb-2 flex items-baseline gap-1">
                        <span class="text-4xl font-bold text-gray-900">{{ $yearly ? Offer::yearlyPerMonth() : Offer::monthly() }}</span>
                        <span class="text-gray-500">/ شهريًا</span>
                    </div>
                    <p class="mb-8 text-xs text-gray-500">{{ $yearly ? 'تُدفع '.Offer::yearly().' كل 12 شهرًا' : 'تُدفع شهريًا' }}</p>

                    <ul class="mb-8 flex-1 space-y-4">
                        @foreach ($plan['features'] as $item)
                            <li class="flex items-center gap-3">
                                <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-green-100 text-green-600">
                                    <x-icon name="check" class="h-3 w-3" stroke="3" />
                                </span>
                                <span class="text-sm font-medium text-gray-600">{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('register', ['plan' => $key]) }}" class="mt-auto block w-full rounded-xl bg-gray-900 py-4 text-center text-sm font-bold text-white transition-colors hover:bg-gray-800">
                        {{ $yearly ? 'اشترك بأفضل قيمة' : 'اشترك شهريًا' }}
                    </a>
                    <a href="{{ route('register', ['plan' => $key, 'trial' => 1]) }}" class="mt-3 block w-full rounded-xl border border-green-300 bg-green-100 py-3 text-center font-medium text-green-700 transition-all hover:border-green-400 hover:bg-green-200">
                        {{ Offer::trialLabel() }}
                    </a>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= لمن المنصة ================= --}}
<section class="bg-gray-50 py-20">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-12 text-center">
            <h2 class="text-3xl font-bold text-gray-900">لمن صُمّم مسوق AI؟</h2>
        </div>
        <div class="grid grid-cols-2 gap-6 md:grid-cols-4">
            @foreach ($audience as [$icon, $label])
                <div class="flex flex-col items-center rounded-xl bg-white p-6 text-center shadow-sm transition-shadow hover:shadow-md">
                    <span class="mb-4 grid h-16 w-16 place-items-center rounded-full bg-amber-50 text-primary">
                        <x-icon :name="$icon" class="h-8 w-8" />
                    </span>
                    <h3 class="font-bold text-gray-800">{{ $label }}</h3>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= الشركاء ================= --}}
<section id="partners" class="relative isolate scroll-mt-20 overflow-hidden bg-white py-16 md:py-20">
    <span class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[420px] bg-[radial-gradient(70%_100%_at_50%_0%,rgba(250,79,0,0.05),transparent_72%)]" aria-hidden="true"></span>
    <span class="pointer-events-none absolute inset-x-0 bottom-0 -z-10 h-40 bg-gradient-to-t from-gray-50/60 to-transparent" aria-hidden="true"></span>

    <div class="mx-auto max-w-7xl px-4 text-center md:px-6">
        <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl border border-gray-200 bg-white text-primary shadow-sm">
            <x-icon name="handshake" class="h-6 w-6" />
        </span>
        <h2 class="mt-5 text-3xl font-bold text-gray-900">الوكلاء والشركاء المعتمدون</h2>
        <p class="mt-2 text-xs font-semibold tracking-[0.25em] text-gray-500" dir="ltr">AUTHORIZED PARTNERS &amp; AGENTS</p>
        <p class="mt-3 text-sm text-gray-500">جهات معتمدة رسميًا لتقديم مسوق AI لعملائها.</p>

        <div class="mx-auto mt-10 grid max-w-2xl gap-5 text-start">
            @forelse (config('marketing.partners') as $partner)
                <article class="flex overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="grid w-32 shrink-0 place-items-center border-e border-gray-100 bg-gray-50 p-4">
                        <img src="{{ asset($partner['logo']) }}" alt="{{ $partner['name'] }}" class="max-h-20 w-auto" loading="lazy">
                    </div>
                    <div class="flex-1 p-6">
                        <span class="inline-flex items-center gap-2 rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-semibold text-gray-700">
                            <x-icon name="check-circle" class="h-3.5 w-3.5 text-primary" />
                            شريك ووكيل معتمد
                            <span class="h-3 w-px bg-orange-200" aria-hidden="true"></span>
                            <span class="text-[10px] tracking-wider text-gray-500" dir="ltr">AUTHORIZED PARTNER</span>
                        </span>
                        <h3 class="mt-3 text-2xl font-bold text-gray-900">{{ $partner['name'] }}</h3>
                        @if (! empty($partner['name_en']))
                            <p class="mt-1 text-sm text-gray-500" dir="ltr">{{ $partner['name_en'] }}</p>
                        @endif
                    </div>
                </article>
            @empty
                <article class="flex overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="grid w-32 shrink-0 place-items-center border-e border-gray-100 bg-gray-50 p-4 text-primary">
                        <x-icon name="handshake" class="h-12 w-12" stroke="1.25" />
                    </div>
                    <div class="flex-1 p-6">
                        <span class="inline-flex items-center gap-2 rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-semibold text-gray-700">
                            <x-icon name="sparkles" class="h-3.5 w-3.5 text-primary" />
                            برنامج الشركاء
                            <span class="h-3 w-px bg-orange-200" aria-hidden="true"></span>
                            <span class="text-[10px] tracking-wider text-gray-500" dir="ltr">PARTNER PROGRAM</span>
                        </span>
                        <h3 class="mt-3 text-2xl font-bold text-gray-900">انضم إلى شركاء مسوق AI</h3>
                        <p class="mt-1 text-sm text-gray-500">كن وكيلًا معتمدًا وقدّم المنصة لعملائك في منطقتك.</p>
                        @if ($salesEmail)
                            <a href="mailto:{{ $salesEmail }}" class="mt-3 inline-flex items-center gap-1.5 text-sm font-bold text-primary-ink hover:underline">
                                تواصل معنا <x-icon name="arrow-left" class="h-4 w-4" />
                            </a>
                        @endif
                    </div>
                </article>
            @endforelse
        </div>
    </div>
</section>

{{-- ================= الأسئلة الشائعة ================= --}}
<section class="bg-gray-50 py-20">
    <div class="mx-auto max-w-4xl px-4">
        <div class="mb-12 text-center">
            <h2 class="mb-4 text-3xl font-bold text-gray-900 md:text-4xl">الأسئلة الشائعة</h2>
            <p class="text-lg text-gray-600">إجابات سريعة عن أكثر ما يسألنا عنه التجار.</p>
        </div>

        <div class="space-y-4">
            @foreach ($faq as [$question, $answer])
                <details class="group overflow-hidden rounded-xl border border-gray-200 bg-white px-6">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-start text-sm font-medium text-gray-900 [&::-webkit-details-marker]:hidden">
                        {{ $question }}
                        <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-200 group-open:rotate-180" />
                    </summary>
                    <p class="pb-5 text-sm leading-relaxed text-gray-600">{{ $answer }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= الدعوة الأخيرة ================= --}}
<section class="py-16 md:py-24">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mx-auto max-w-4xl rounded-2xl border-2 border-orange-500 bg-white p-8 text-center md:p-12">
            <span class="mb-8 inline-flex items-center gap-2 rounded-full border border-orange-200 bg-orange-50 px-4 py-1 text-primary-ink">
                <x-icon name="sparkles" class="h-4 w-4" />
                <span class="text-sm font-medium">ابدأ رحلتك اليوم</span>
            </span>

            <h2 class="mb-6 text-3xl font-bold leading-tight text-gray-900 md:text-5xl">
                جاهز تحوّل منتجاتك إلى<br>
                <span class="text-primary">محتوى يبيع؟</span>
            </h2>
            <p class="mx-auto mb-8 max-w-2xl text-lg leading-relaxed text-gray-600 md:text-xl">
                انضم إلى رواد الأعمال والمسوقين الذين يكتبون محتواهم بهويتهم، ويختصرون ساعات الكتابة مع مسوق AI.
            </p>

            <div class="flex flex-col items-center justify-center gap-4 sm:flex-row">
                <a href="{{ route('register') }}" class="{{ $btnPrimary }} px-10">
                    اشترك الآن بـ {{ Offer::monthly() }}
                    <x-icon name="arrow-left" class="h-5 w-5" />
                </a>
                @if ($salesEmail)
                    <a href="mailto:{{ $salesEmail }}" class="inline-flex w-full items-center justify-center rounded-full border-2 border-gray-300 px-8 py-4 text-lg font-bold text-gray-700 transition-colors hover:bg-gray-50 sm:w-auto">تواصل مع المبيعات</a>
                @endif
            </div>

            <div class="mt-6 flex justify-center">
                <a href="{{ route('register', ['trial' => 1]) }}" class="inline-flex w-full items-center justify-center gap-2 rounded-full border-2 border-primary bg-white px-8 py-4 text-[1.1875rem] font-bold text-primary transition-colors hover:bg-amber-50 sm:w-auto">
                    <x-icon name="pointer-click" class="h-5 w-5" />
                    {{ Offer::trialLabel() }}
                </a>
            </div>
        </div>
    </div>
</section>

@endsection
