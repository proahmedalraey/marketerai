@extends('layouts.public')

@section('title', 'محتوى تسويقي عربي يبيع بالذكاء الاصطناعي')

@use('App\Support\Offer')

@section('content')

@php
    $guarantee = config('marketing.guarantee');
    $salesEmail = config('marketing.contact.email');

    // لقطات حقيقية من الحساب التجريبي بدقة مضاعفة (public/images/marketing/tour-*.webp)
    $tour = [
        ['writer', 'الكتابة', 'منشور جاهز بصوت علامتك', 'شاشة كتابة المحتوى: الهدف والمنتج والمنصة واللهجة'],
        ['carousel', 'الكاروسيل', 'شرائح بهويتك تعدّلها كما تريد', 'محرر الكاروسيل: شرائح بألوان العلامة ونصوص قابلة للتعديل'],
        ['studio', 'الصور', 'صور منتجات بدقة حتى 4K', 'استوديو الصور: صور منتجات مولّدة وكاروسيلات جاهزة'],
        ['voiceover', 'الصوت', 'تعليق صوتي بلهجة جمهورك', 'التعليق الصوتي: أنماط الإلقاء ونص جاهز للتسجيل'],
    ];

    // ستوريات ولّدتها المنصة لمتجر الحساب التجريبي: صورة من الاستوديو مع النص الذي كُتب لها نفسها
    // (public/images/marketing/story-*.jpg مقصوصة 9:16). استُبعدت الشرائح التي فيها أسعار وأرقام تواصل.
    $stories = [
        ['story-pour', 'تبحث عن نكهة تميز قهوتك الحين؟ سيروب المستكة اليوناني هو الحل.', 'باريستا يصب سيروب المستكة في فنجان قهوة'],
        ['story-mastic', 'مناسب للقهوة الساخنة والباردة والماتشا والآيس كريم للمقاهي والبيت.', 'عبوة سيروب المستكة بجانب كأس ماتشا لاتيه مثلّج'],
        ['story-white-chocolate', 'وش الفرق؟ الشوكولاتة البيضاء في كوبك تتغير مع صوص واحد', 'عبوة صوص الشوكولاتة البيضاء بجانب كوب لاتيه'],
        ['story-dates', null, 'تمر وفنجان قهوة على صينية نحاسية'],
    ];

    // ألوان شعارات المنصات في ختام مشهد التجميع (أدوات المشهد نفسها في config/marketing.php: tools)
    $platformColors = [
        'instagram' => 'bg-white text-[#E4405F]',
        'tiktok' => 'bg-white text-gray-900',
        'x' => 'bg-white text-gray-900',
        'facebook' => 'bg-white text-[#1877F2]',
        'linkedin' => 'bg-white text-[#0A66C2]',
        'youtube' => 'bg-white text-[#FF0000]',
        'pinterest' => 'bg-white text-[#E60023]',
        'snapchat' => 'bg-[#FFFC00] text-gray-900',
    ];

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
        ['كيف أتواصل مع الدعم الفني؟', 'من قسم «تواصل معنا» أسفل الصفحة اختر «خدمة العملاء»، ونرد عليك في أقرب وقت.'],
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
                        <x-public.icon name="rocket" class="h-4 w-4 text-primary" />
                        محتوى بهويتك.. يبيع عنك.
                    </span>

                    <h1 data-reveal class="text-4xl font-bold leading-tight text-gray-900 md:text-5xl lg:text-6xl">
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
                        <x-public.icon name="arrow-left" class="h-5 w-5" />
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

            {{-- ستوريات حقيقية من الحساب التجريبي تتقدّم داخل جوال (resources/js/landing/stories.js) --}}
            <div class="relative w-full lg:w-1/2">
                <div class="relative mx-auto w-[17rem] sm:w-[19rem]">
                    <div class="absolute -inset-12 -z-10 rounded-full bg-amber-200/40 blur-3xl" aria-hidden="true"></div>

                    <div class="relative rotate-2 rounded-[2.75rem] bg-gray-900 p-2.5 shadow-2xl shadow-gray-900/30 transition-transform duration-500 hover:rotate-0"
                         data-stories role="region" aria-roledescription="ستوريات" aria-label="أمثلة ستوري ولّدها مسوق AI">
                        <div class="relative aspect-[9/16] overflow-hidden rounded-[2.2rem] bg-gray-800">
                            @foreach ($stories as $i => [$image, $text, $alt])
                                <figure data-story class="story-frame {{ $i ? '' : 'is-active' }}" aria-hidden="{{ $i ? 'true' : 'false' }}">
                                    <img src="{{ asset('images/marketing/'.$image.'.jpg') }}" alt="{{ $alt }}" width="720" height="1280"
                                         class="story-img" decoding="async" fetchpriority="{{ $i ? 'low' : 'high' }}">
                                    <span class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent" aria-hidden="true"></span>
                                    @if ($text)
                                        <figcaption class="story-text absolute inset-x-5 bottom-20 text-start text-lg font-bold leading-snug text-white [text-shadow:0_2px_10px_rgb(0_0_0/0.45)]">{{ $text }}</figcaption>
                                    @endif
                                </figure>
                            @endforeach

                            {{-- رأس الستوري: أشرطة التقدّم وحساب المتجر --}}
                            <div class="pointer-events-none absolute inset-x-0 top-0 z-10 h-28 bg-gradient-to-b from-black/50 to-transparent" aria-hidden="true"></div>
                            <div class="absolute inset-x-3 top-3 z-20 flex gap-1" aria-hidden="true">
                                @foreach ($stories as $story)
                                    <span class="h-[3px] flex-1 overflow-hidden rounded-full bg-white/35">
                                        <span data-story-bar class="story-bar block h-full w-full rounded-full bg-white"></span>
                                    </span>
                                @endforeach
                            </div>
                            <div class="absolute inset-x-3 top-6 z-20 flex items-center gap-2 text-white" aria-hidden="true">
                                <img src="{{ asset('images/marketing/story-avatar.jpg') }}" alt="" width="96" height="96" class="h-8 w-8 rounded-full object-cover ring-2 ring-white/80">
                                <span class="text-sm font-bold">إمدادات القهوة</span>
                                <span class="text-xs text-white/75">إنستغرام</span>
                            </div>

                            {{-- خانة الرد: شكل الستوري فقط --}}
                            <div class="absolute inset-x-4 bottom-4 z-20 flex items-center gap-3 text-white" aria-hidden="true">
                                <span class="flex-1 rounded-full border border-white/60 px-4 py-2 text-sm text-white/85">أرسل رسالة</span>
                                <x-public.icon name="heart" class="h-6 w-6" />
                                <x-public.icon name="send" class="h-6 w-6" />
                            </div>

                            {{-- النقر: النصف الأيسر يتقدّم والأيمن يرجع — اتجاه القراءة العربية --}}
                            <button type="button" data-story-next class="absolute inset-y-0 left-0 z-30 w-1/2" aria-label="الستوري التالية"></button>
                            <button type="button" data-story-prev class="absolute inset-y-0 right-0 z-30 w-1/2" aria-label="الستوري السابقة"></button>
                        </div>
                    </div>

                    <div class="absolute -bottom-6 -right-10 z-20 rounded-xl border border-gray-100 bg-white p-4 shadow-xl motion-safe:animate-float-y" aria-hidden="true">
                        <div class="flex items-center gap-3">
                            <span class="grid h-10 w-10 place-items-center rounded-lg bg-green-100 text-green-600">
                                <x-public.icon name="check-circle" class="h-5 w-5" />
                            </span>
                            <div>
                                <p class="font-bold text-gray-800">ستوري جاهز للنشر</p>
                                <p class="text-xs font-semibold text-green-700">بلهجة جمهور المتجر</p>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="mt-12 text-center text-sm text-gray-500">أمثلة حقيقية ولّدها مسوق AI لمتجر إمدادات القهوة</p>
            </div>
        </div>
    </div>
</section>

{{-- ================= جولة في المنصة ================= --}}
<section class="relative isolate overflow-hidden bg-white py-24">
    <div class="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-amber-50/50 via-white to-white" aria-hidden="true"></div>

    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-16 text-center">
            <h2 class="mb-6 text-3xl font-bold text-gray-900 md:text-5xl">شاهد مسوق AI أثناء العمل</h2>
            <p class="mx-auto max-w-2xl text-lg text-gray-500">جولة سريعة في أهم الشاشات: من كتابة المنشور حتى التعليق الصوتي.</p>
        </div>

        {{-- الشاشات تتبدّل على ساعة (resources/js/landing/showcase.js)؛ بلا سكربت تبقى الأولى ظاهرة --}}
        <div class="relative mx-auto max-w-5xl" data-showcase role="region" aria-roledescription="عرض متتابع" aria-label="جولة في شاشات مسوق AI">
            <div data-deck aria-live="off"
                 class="relative aspect-video overflow-hidden rounded-3xl border border-gray-200 bg-gray-100 shadow-[0_25px_60px_-15px_rgba(0,0,0,0.30),0_10px_30px_-10px_rgba(217,119,6,0.35)]">
                @foreach ($tour as $i => [$key, $title, $caption, $alt])
                    <figure data-slide role="group" aria-roledescription="شاشة" aria-label="{{ $i + 1 }} من {{ count($tour) }}: {{ $title }}"
                            aria-hidden="{{ $i ? 'true' : 'false' }}"
                            class="tour-slide absolute inset-0 overflow-hidden transition-opacity duration-500 ease-out {{ $i ? 'opacity-0' : 'is-on' }}">
                        <img src="{{ asset('images/marketing/tour-'.$key.'.webp') }}" alt="{{ $alt }}"
                             width="2880" height="1620" loading="lazy" decoding="async"
                             class="h-full w-full object-cover object-top">
                    </figure>
                @endforeach
            </div>

            <div class="mt-6 flex items-start gap-4 md:gap-6">
                <div class="grid flex-1 grid-cols-4 gap-3 md:gap-5">
                    @foreach ($tour as $i => [$key, $title, $caption])
                        <button type="button" data-tick aria-current="{{ $i ? 'false' : 'true' }}" class="group py-2 text-start">
                            <span class="block h-1 overflow-hidden rounded-full bg-gray-200">
                                <span data-fill class="block h-full w-0 rounded-full bg-primary"></span>
                            </span>
                            <span class="mt-3 block text-sm font-bold text-gray-500 transition-colors group-hover:text-gray-800 group-aria-[current=true]:text-gray-900 md:text-base">{{ $title }}</span>
                            <span class="mt-1 hidden text-sm leading-snug text-gray-500 md:block">{{ $caption }}</span>
                        </button>
                    @endforeach
                </div>

                <button type="button" data-toggle aria-label="إيقاف العرض التلقائي"
                        class="mt-1 grid h-11 w-11 shrink-0 place-items-center rounded-full border border-gray-300 bg-white text-gray-700 transition-colors hover:bg-gray-50">
                    <x-public.icon name="pause" class="h-4 w-4" data-when="playing" />
                    <x-public.icon name="play" class="hidden h-4 w-4" data-when="paused" />
                </button>
            </div>

            <div class="absolute -right-12 -top-12 -z-10 h-24 w-24 rounded-full bg-amber-400/20 blur-2xl" aria-hidden="true"></div>
        </div>
    </div>
</section>

{{-- ================= كل الأدوات في منصة واحدة ================= --}}
{{-- مشهد يثبت أثناء التمرير (resources/js/landing/assembly.js)؛ بلا سكربت أو مع «تقليل الحركة» يظهر مشهده الأخير --}}
<section data-assembly class="assembly" aria-labelledby="assembly-title">
    <div data-assembly-stage class="assembly-stage">
        <div data-assembly-header class="assembly-header absolute inset-x-4 top-[18%] text-center md:top-[22%]">
            <span class="rounded-full bg-white px-3 py-1 text-sm font-bold text-primary-ink shadow-sm ring-1 ring-orange-200">الحل الشامل</span>
            <p class="mx-auto mt-6 max-w-3xl text-3xl font-bold leading-tight text-gray-900 md:text-5xl">فريق تسويق كامل… باشتراك واحد؟</p>
            <p class="mx-auto mt-4 max-w-xl text-lg text-gray-600">كاتب ومصمم ومعلّق صوتي ومخطط ومدقق… مرّر لتراهم يجتمعون.</p>
        </div>

        <div data-assembly-final class="relative mx-auto max-w-4xl text-center">
            {{-- الخيوط تُرسم هنا من «وانشر بثقة» إلى المنصات (assembly.js)؛ بلا سكربت تظهر المنصات بلا خيوط --}}
            <svg data-assembly-threads class="pointer-events-none absolute inset-0 h-full w-full overflow-visible" aria-hidden="true"></svg>

            <p data-assembly-seg class="relative mb-6 inline-flex rounded-full bg-amber-100 px-3 py-1 text-sm font-bold text-amber-800 md:mb-8">كل أدوات التسويق في منصة واحدة</p>

            <h2 id="assembly-title" class="relative text-[2rem] font-bold leading-[1.5] text-gray-900 md:text-6xl md:leading-[1.4]">
                {{-- الأداة وفعلها وحدة واحدة: التفاف السطر لا يفصل الأيقونة عن كلمتها --}}
                @foreach (config('marketing.tools') as $tool)
                    <span class="whitespace-nowrap">
                        <span class="assembly-hole" aria-hidden="true">
                            <span data-assembly-chip class="assembly-chip {{ $tool['color'] }}">
                                <x-public.icon :name="$tool['icon']" class="size-1/2" stroke="2" />
                                <span data-assembly-label class="assembly-label">{{ $tool['name'] }}</span>
                            </span>
                        </span>
                        <span data-assembly-seg @if ($loop->last) data-assembly-publish-from @endif>{{ $tool['line'] }}</span>
                    </span>
                @endforeach
            </h2>

            {{-- المنصات الثماني التي يكتب لها مسوق AI (config/content.php)، تمتد إليها الخيوط من «وانشر بثقة» --}}
            {{-- الثماني في سطر واحد حتى على الجوال، كي لا يعبر خيط بين الأيقونات إلى سطر ثانٍ --}}
            <ul class="relative mx-auto mt-12 flex max-w-xl justify-center gap-1.5 sm:gap-2.5 md:mt-16 md:gap-4" aria-label="المنصات التي يكتب لها مسوق AI">
                @foreach (config('content.platforms') as $key => $platform)
                    <li data-assembly-platform class="grid h-9 w-9 shrink-0 place-items-center rounded-full shadow-md ring-1 ring-gray-100 sm:h-10 sm:w-10 md:h-12 md:w-12 {{ $platformColors[$key] ?? 'bg-white text-gray-900' }}">
                        <x-public.icon :name="$platform['icon']" class="h-4 w-4 sm:h-5 sm:w-5" />
                        <span class="sr-only">{{ $platform['label'] }}</span>
                    </li>
                @endforeach
            </ul>
            <p data-assembly-at="0.885" class="relative mt-3 text-sm text-gray-500">بصيغة كل منصة وطولها</p>

            <p data-assembly-at="0.905" class="relative mt-6 text-[2rem] font-bold leading-snug text-primary md:mt-8 md:text-5xl">كلها في مسوق AI.</p>

            <div data-assembly-outro class="relative mt-6 md:mt-8">
                <p class="text-lg text-gray-600 md:text-xl">كل ذلك مقابل <span class="font-bold text-primary-ink">{{ Offer::monthly() }} شهريًا</span> فقط.</p>
                <a href="{{ route('register') }}" class="{{ $btnPrimary }} mt-6">ابدأ مع مسوق AI</a>
            </div>
        </div>
    </div>
</section>

{{-- ================= المميزات ================= --}}
<section id="features" class="scroll-mt-20 bg-gray-50/50 py-24">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-16 text-center">
            <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-bold text-amber-800">ماذا يشمل اشتراكك؟</span>
            <h2 data-reveal class="mb-4 mt-6 text-3xl font-bold text-gray-900 md:text-5xl">
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
                                <x-public.icon :name="$icon" class="h-5 w-5" />
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
            <h2 data-reveal class="mb-6 text-3xl font-bold text-gray-900 md:text-5xl">كيف يعمل مسوق AI؟</h2>
            <p class="text-lg text-gray-500">خمس خطوات تفصلك عن أول منشور جاهز.</p>
        </div>

        {{-- الخط يُرسم مع التمرير وتضيء الخطوات (resources/js/landing/timeline.js)؛ بلا سكربت يبقى الخطان الرماديان --}}
        <div class="timeline relative mx-auto max-w-4xl" data-timeline>
            <div data-timeline-fallback class="absolute bottom-0 left-1/2 top-0 hidden w-0.5 -translate-x-1/2 bg-gray-200 md:block" aria-hidden="true"></div>
            <div data-timeline-fallback class="absolute bottom-0 top-0 start-8 w-0.5 bg-gray-200 md:hidden" aria-hidden="true"></div>

            <svg data-timeline-svg class="pointer-events-none absolute inset-0 hidden h-full w-full overflow-visible" aria-hidden="true">
                <path data-track fill="none" class="stroke-gray-200" stroke-width="3" stroke-linecap="round" />
                <path data-draw fill="none" class="stroke-primary" stroke-width="3" stroke-linecap="round" />
            </svg>

            <ol class="space-y-12 md:space-y-24">
                @foreach ($steps as $i => [$icon, $title, $text])
                    @php $flip = $i % 2 === 1; @endphp
                    <li data-step class="timeline-step relative flex flex-col items-center gap-8 md:flex-row {{ $flip ? 'md:flex-row-reverse' : '' }}">
                        <div class="w-full flex-1 ps-20 text-start md:w-auto {{ $flip ? 'md:pe-12 md:ps-0' : 'md:ps-12 md:text-end' }}">
                            <div class="timeline-card relative rounded-2xl border border-amber-100 bg-amber-50/50 p-6 hover:shadow-md">
                                {{-- سهم البطاقة يتجه نحو الخط الزمني --}}
                                <span class="absolute top-1/2 hidden h-4 w-4 -translate-y-1/2 rotate-45 border-amber-100 bg-amber-50 md:block {{ $flip ? '-right-2 border-r border-t' : '-left-2 border-b border-l' }}" aria-hidden="true"></span>
                                <h3 class="mb-3 text-xl font-bold text-gray-900">{{ $title }}</h3>
                                <p class="leading-relaxed text-gray-500">{{ $text }}</p>
                            </div>
                        </div>

                        <div data-dot class="timeline-dot absolute start-0 z-10 grid h-16 w-16 shrink-0 place-items-center rounded-full border-4 border-amber-500 bg-white shadow-lg shadow-amber-200/50 md:relative md:start-auto">
                            <x-public.icon :name="$icon" class="h-6 w-6 text-amber-600" />
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
<section id="pricing" class="scroll-mt-20 bg-white py-16 md:py-24">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-12 text-center md:mb-16">
            <h2 data-reveal class="mb-6 text-3xl font-bold text-gray-900 md:text-5xl">اختر الباقة المناسبة لك</h2>
            <p class="text-lg text-gray-500">ابدأ بالشهرية بمرونة كاملة، أو وفّر مع السنوية.</p>
        </div>

        <div class="mx-auto grid max-w-5xl grid-cols-1 gap-10 md:grid-cols-2 md:gap-8">
            @foreach (['monthly', 'yearly'] as $key)
                @php
                    $plan = config("marketing.plans.$key");
                    $yearly = $key === 'yearly';
                    $features = array_map([Offer::class, 'feature'], $plan['features']);
                    $shown = array_slice($features, 0, $plan['visible']);
                    $more = array_slice($features, $plan['visible']);
                @endphp
                {{-- على الجوال تأتي السنوية أولًا: الخيار الموصى به قبل البديل، بوهج يدور على إطارها وطفو بطيء (app.css: .plan-featured) --}}
                <article class="relative flex flex-col rounded-3xl p-6 sm:p-8 {{ $yearly ? 'plan-featured order-first md:order-none' : 'border border-gray-200 bg-white transition-colors hover:border-amber-400' }}">
                    @if ($yearly && Offer::yearlySaving() > 0)
                        <span class="absolute left-1/2 top-0 -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded-full bg-gray-900 px-4 py-1 text-xs font-bold text-white">{{ Offer::yearlySavingLabel() }}</span>
                    @endif

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">{{ $plan['label'] }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ $plan['hint'] }}</p>
                        </div>
                        @if ($yearly)
                            <span class="shrink-0 rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">الأكثر توفيرًا</span>
                        @endif
                    </div>

                    <div class="mt-6 flex items-baseline gap-1.5">
                        <span class="text-5xl font-bold text-gray-900">{{ $yearly ? Offer::yearlyPerMonth() : Offer::monthly() }}</span>
                        <span class="text-gray-500">/ شهريًا</span>
                    </div>
                    <p class="mt-2 text-sm text-gray-500">
                        @if ($yearly)
                            تُدفع {{ Offer::yearly() }} كل 12 شهرًا · <span class="font-semibold text-green-700">توفّر {{ Offer::yearlySavingAmount() }}</span>
                        @else
                            تُدفع شهريًا
                        @endif
                    </p>

                    <div class="my-8 flex-1 border-t border-gray-100 pt-8" x-data="{ more: false }">
                        <ul class="space-y-4">
                            @foreach ($shown as $item)
                                <x-public.check-item>{{ $item }}</x-public.check-item>
                            @endforeach
                        </ul>

                        @if ($more)
                            {{-- بلا سكربت تظهر كل المزايا ويختفي الزر؛ مع Alpine تُطوى الإضافية خلفه --}}
                            <ul id="plan-more-{{ $key }}" x-show="more" x-transition.opacity.duration.300ms class="mt-4 space-y-4">
                                @foreach ($more as $item)
                                    <x-public.check-item>{{ $item }}</x-public.check-item>
                                @endforeach
                            </ul>

                            <button type="button" x-cloak @click="more = !more" :aria-expanded="more.toString()" aria-controls="plan-more-{{ $key }}"
                                    class="mt-5 inline-flex min-h-11 items-center gap-2 rounded-full bg-gray-50 px-4 text-sm font-bold text-gray-700 ring-1 ring-gray-200 transition-colors hover:bg-gray-100">
                                <span x-text="more ? 'إخفاء المزايا الإضافية' : 'عرض كل المزايا (+{{ count($more) }})'">عرض كل المزايا (+{{ count($more) }})</span>
                                <x-public.icon name="chevron-down" class="h-4 w-4 transition-transform duration-300" x-bind:class="more && 'rotate-180'" />
                            </button>
                        @endif
                    </div>

                    <a href="{{ route('register', ['plan' => $key]) }}" class="block w-full rounded-xl bg-gray-900 py-4 text-center text-sm font-bold text-white transition-colors hover:bg-gray-800">
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
                        <x-public.icon :name="$icon" class="h-8 w-8" />
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
            <x-public.icon name="handshake" class="h-6 w-6" />
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
                            <x-public.icon name="check-circle" class="h-3.5 w-3.5 text-primary" />
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
                        <x-public.icon name="handshake" class="h-12 w-12" stroke="1.25" />
                    </div>
                    <div class="flex-1 p-6">
                        <span class="inline-flex items-center gap-2 rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-semibold text-gray-700">
                            <x-public.icon name="sparkles" class="h-3.5 w-3.5 text-primary" />
                            برنامج الشركاء
                            <span class="h-3 w-px bg-orange-200" aria-hidden="true"></span>
                            <span class="text-[10px] tracking-wider text-gray-500" dir="ltr">PARTNER PROGRAM</span>
                        </span>
                        <h3 class="mt-3 text-2xl font-bold text-gray-900">انضم إلى شركاء مسوق AI</h3>
                        <p class="mt-1 text-sm text-gray-500">كن وكيلًا معتمدًا وقدّم المنصة لعملائك في منطقتك.</p>
                        @if ($salesEmail)
                            <a href="mailto:{{ $salesEmail }}" class="mt-3 inline-flex items-center gap-1.5 text-sm font-bold text-primary-ink hover:underline">
                                تواصل معنا <x-public.icon name="arrow-left" class="h-4 w-4" />
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

        {{-- name="faq" يجعلها مجموعة واحدة: فتح سؤال يغلق السابق دون سكربت --}}
        <div class="space-y-4">
            @foreach ($faq as [$question, $answer])
                <details name="faq" class="faq-item group rounded-2xl border border-gray-200 bg-white px-5 transition-shadow duration-300 open:border-amber-200 open:shadow-lg open:shadow-amber-100/60 md:px-6">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-start text-[15px] font-semibold text-gray-900 [&::-webkit-details-marker]:hidden">
                        <span>{{ $question }}</span>
                        <span class="relative grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white text-gray-700 shadow-md shadow-gray-900/10 ring-1 ring-gray-100 transition-colors duration-300 group-open:bg-primary group-open:text-white group-open:ring-primary" aria-hidden="true">
                            <span class="absolute h-0.5 w-3.5 rounded-full bg-current"></span>
                            <span class="absolute h-3.5 w-0.5 rounded-full bg-current transition-transform duration-300 group-open:rotate-90 group-open:scale-0"></span>
                        </span>
                    </summary>
                    <p class="pb-5 text-sm leading-loose text-gray-600">{{ $answer }}</p>
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
                <x-public.icon name="sparkles" class="h-4 w-4" />
                <span class="text-sm font-medium">ابدأ رحلتك اليوم</span>
            </span>

            <h2 data-reveal class="mb-6 text-3xl font-bold leading-tight text-gray-900 md:text-5xl">
                جاهز تحوّل منتجاتك إلى<br>
                <span class="text-primary">محتوى يبيع؟</span>
            </h2>
            <p class="mx-auto mb-8 max-w-2xl text-lg leading-relaxed text-gray-600 md:text-xl">
                انضم إلى رواد الأعمال والمسوقين الذين يكتبون محتواهم بهويتهم، ويختصرون ساعات الكتابة مع مسوق AI.
            </p>

            <div class="flex flex-col items-center justify-center gap-4 sm:flex-row">
                <a href="{{ route('register') }}" class="{{ $btnPrimary }} px-10">
                    اشترك الآن بـ {{ Offer::monthly() }}
                    <x-public.icon name="arrow-left" class="h-5 w-5" />
                </a>
                @if ($salesEmail)
                    <a href="mailto:{{ $salesEmail }}" class="inline-flex w-full items-center justify-center rounded-full border-2 border-gray-300 px-8 py-4 text-lg font-bold text-gray-700 transition-colors hover:bg-gray-50 sm:w-auto">تواصل مع المبيعات</a>
                @endif
            </div>

            <div class="mt-6 flex justify-center">
                <a href="{{ route('register', ['trial' => 1]) }}" class="inline-flex w-full items-center justify-center gap-2 rounded-full border-2 border-primary bg-white px-8 py-4 text-[1.1875rem] font-bold text-primary transition-colors hover:bg-amber-50 sm:w-auto">
                    <x-public.icon name="pointer-click" class="h-5 w-5" />
                    {{ Offer::trialLabel() }}
                </a>
            </div>
        </div>
    </div>
</section>

@endsection

@push('scripts')
    @vite('resources/js/landing.js')
@endpush
