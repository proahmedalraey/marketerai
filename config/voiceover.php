<?php

/*
|--------------------------------------------------------------------------
| التعليق الصوتي (Beta)
|--------------------------------------------------------------------------
| كل ما يغيّر الإلقاء هنا لا في الكود: المذيعون وأصواتهم الفعلية عند المزود،
| وأنماط الإلقاء وتوجيهها، واللهجات، ومستويات الجودة ونماذجها.
|
| التوجيهات (direction) بالإنجليزية عمداً: نماذج Gemini TTS تلتزم بها أدق من العربية،
| وهي لا تُنطق — النموذج يؤدي ما تحت TRANSCRIPT وحده (مُجرَّب على gemini-3.8-flash-tts).
*/

return [

    // حد النص الواحد: نحو دقيقتين ونصف من الكلام، وما فوقه يُقسَّم على أكثر من تعليق
    'max_chars' => 2000,

    // ما يُنطق في الدقيقة لتقدير الحجز قبل التوليد. أقل من المعتاد (~140) عمداً:
    // الحجز الزائد يُرجَع عند التسوية، والناقص يتحمله المتجر لا التاجر.
    'words_per_minute' => 120,

    // يُحذف الملف بعد هذه المدة ما لم يحفظه التاجر (voiceover:prune يومياً)
    'retention_days' => 30,

    // وقت الإنشاء في السجل بتوقيت التاجر: التطبيق يعمل بـUTC (لا config/app.php يقرأ APP_TIMEZONE)
    'display_timezone' => env('VOICEOVER_TIMEZONE', env('APP_TIMEZONE', 'Asia/Riyadh')),

    /*
    | مستويات الجودة: اسمها للتاجر، والنموذج الفعلي، ومفتاح تكلفة الدقيقة في credits.php
    */
    'tiers' => [
        'standard' => [
            'label' => 'Marketerai 2.3',
            'short' => '2.3',
            'hint' => 'كفاءة وسرعة أعلى',
            'icon' => 'zap',
            'model' => env('VOICEOVER_STANDARD_MODEL', 'gemini-3.8-flash-lite-tts'),
            'credits' => 'voice.minute',
        ],
        'hd' => [
            'label' => 'Marketerai 3.1',
            'short' => '3.1',
            'hint' => 'نقي ودقيق',
            'icon' => 'gem',
            'model' => env('VOICEOVER_HD_MODEL', 'gemini-3.8-flash-tts'),
            'credits' => 'voice.minute_hd',
        ],
    ],

    'default_tier' => 'hd',

    /*
    | المذيعون: الاسم للتاجر، والصوت الفعلي عند المزود (أصوات Gemini الجاهزة)، وطابعه.
    | avatar اختياري: مسار صورة في public/ — بدونه تظهر أيقونة الحرف الأول.
    */
    'voices' => [
        // ذكور
        'khalid' => ['name' => 'خالد', 'en' => 'Khalid', 'gender' => 'male', 'provider_voice' => 'Charon', 'tone' => 'رزين ومعلوماتي'],
        'mutaib' => ['name' => 'متعب', 'en' => 'Mutaib', 'gender' => 'male', 'provider_voice' => 'Orus', 'tone' => 'حازم وواثق'],
        'ahmed' => ['name' => 'أحمد', 'en' => 'Ahmed', 'gender' => 'male', 'provider_voice' => 'Iapetus', 'tone' => 'واضح ومباشر'],
        'hani' => ['name' => 'هاني', 'en' => 'Hani', 'gender' => 'male', 'provider_voice' => 'Puck', 'tone' => 'مرح ومتفائل'],
        'faris' => ['name' => 'فارس', 'en' => 'Faris', 'gender' => 'male', 'provider_voice' => 'Fenrir', 'tone' => 'حماسي ومندفع'],
        'waleed' => ['name' => 'وليد', 'en' => 'Waleed', 'gender' => 'male', 'provider_voice' => 'Achird', 'tone' => 'ودود وقريب'],
        'sultan' => ['name' => 'سلطان', 'en' => 'Sultan', 'gender' => 'male', 'provider_voice' => 'Algieba', 'tone' => 'ناعم وسلس'],
        'abdulrahman' => ['name' => 'عبدالرحمن', 'en' => 'Abdulrahman', 'gender' => 'male', 'provider_voice' => 'Sadaltager', 'tone' => 'خبير وموثوق'],
        'mansour' => ['name' => 'منصور', 'en' => 'Mansour', 'gender' => 'male', 'provider_voice' => 'Algenib', 'tone' => 'عميق وأجش'],

        // إناث
        'hadeel' => ['name' => 'هديل', 'en' => 'Hadeel', 'gender' => 'female', 'provider_voice' => 'Kore', 'tone' => 'حازمة وواثقة'],
        'sara' => ['name' => 'سارة', 'en' => 'Sara', 'gender' => 'female', 'provider_voice' => 'Zephyr', 'tone' => 'مشرقة ومبهجة'],
        'reem' => ['name' => 'ريم', 'en' => 'Reem', 'gender' => 'female', 'provider_voice' => 'Aoede', 'tone' => 'خفيفة ومنسابة'],
        'huda' => ['name' => 'هدى', 'en' => 'Huda', 'gender' => 'female', 'provider_voice' => 'Sulafat', 'tone' => 'دافئة وحنونة'],
        'noura' => ['name' => 'نورة', 'en' => 'Noura', 'gender' => 'female', 'provider_voice' => 'Leda', 'tone' => 'شابة وحيوية'],
        'dana' => ['name' => 'دانة', 'en' => 'Dana', 'gender' => 'female', 'provider_voice' => 'Despina', 'tone' => 'ناعمة وسلسة'],
        'ghada' => ['name' => 'غادة', 'en' => 'Ghada', 'gender' => 'female', 'provider_voice' => 'Gacrux', 'tone' => 'ناضجة ورزينة'],
        'jouri' => ['name' => 'جوري', 'en' => 'Jouri', 'gender' => 'female', 'provider_voice' => 'Laomedeia', 'tone' => 'مرحة ونشيطة'],
        'aisha' => ['name' => 'عائشة', 'en' => 'Aisha', 'gender' => 'female', 'provider_voice' => 'Vindemiatrix', 'tone' => 'هادئة ولطيفة'],
    ],

    'default_voice' => 'hadeel',

    /*
    | أنماط الإلقاء. custom يأخذ توجيه التاجر نفسه بدل direction.
    */
    'styles' => [
        'custom' => [
            'label' => 'أسلوب مخصص',
            'icon' => 'pencil',
            'hint' => 'اكتب كيف تريد أن يُلقى النص',
            'direction' => null,
        ],
        'humor' => [
            'label' => 'فكاهي',
            'icon' => 'smile',
            'hint' => 'خفيف الظل ومبتسم',
            'direction' => 'Light-hearted and playful, with a smile in the voice and comic timing on the punchlines. Never mocking.',
        ],
        'news' => [
            'label' => 'خبر وتقرير',
            'icon' => 'newspaper',
            'hint' => 'رصين كنشرة إخبارية',
            'direction' => 'A composed news anchor: clear, measured, authoritative and neutral, with crisp articulation and steady pacing.',
        ],
        'story' => [
            'label' => 'سرد قصصي',
            'icon' => 'book-open',
            'hint' => 'يحكي بتشويق وهدوء',
            'direction' => 'A warm storyteller: intimate and engaging, building gentle suspense, with natural pauses before key moments.',
        ],
        'pitch' => [
            'label' => 'عرض تشجيعي',
            'icon' => 'megaphone',
            'hint' => 'متحمس ومحفّز',
            'direction' => 'An inspiring motivational presenter: confident, uplifting and energetic, emphasising benefits and ending with conviction.',
        ],
        'podcast' => [
            'label' => 'بودكاست',
            'icon' => 'podcast',
            'hint' => 'حواري وطبيعي',
            'direction' => 'A relaxed podcast host talking to a friend: conversational, natural and unhurried, with casual warmth.',
        ],
        'reels' => [
            'label' => 'ريلز وتيك توك',
            'icon' => 'reel',
            'hint' => 'سريع وجذاب للفيديو القصير',
            'direction' => 'An energetic short-form video creator (Reels/TikTok): punchy, upbeat and fast-paced, with a strong hook in the first words.',
        ],
        'product_ad' => [
            'label' => 'إعلان منتج',
            'icon' => 'shopping-bag',
            'hint' => 'إعلاني واضح ومقنع',
            'direction' => 'A polished commercial voice-over: persuasive, bright and trustworthy, highlighting the product clearly with a confident call to action.',
        ],
    ],

    'default_style' => 'reels',

    /*
    | اللهجات العربية. variants لهجات فرعية تظهر تحت أمها (سعودية ← بيضاء/حجازية/نجدية).
    */
    'dialects' => [
        'auto' => [
            'label' => 'تلقائي',
            'direction' => 'Use the Arabic dialect the transcript is written in; if it is Modern Standard Arabic, keep it standard.',
        ],
        'msa' => [
            'label' => 'فصحى',
            'direction' => 'Modern Standard Arabic (fusha) with correct, clear pronunciation.',
        ],
        'saudi' => [
            'label' => 'سعودية',
            'direction' => 'Saudi Arabic dialect.',
            'variants' => [
                'white' => ['label' => 'بيضاء', 'direction' => 'Neutral "white" Saudi/Gulf Arabic, easily understood across the Gulf.'],
                'hijazi' => ['label' => 'حجازية', 'direction' => 'Hijazi Saudi Arabic (Jeddah, Makkah) accent.'],
                'najdi' => ['label' => 'نجدية (الرياض)', 'direction' => 'Najdi Saudi Arabic (Riyadh) accent.'],
            ],
            'default_variant' => 'white',
        ],
        'egyptian' => ['label' => 'مصرية', 'direction' => 'Egyptian Arabic (Cairene) accent.'],
        'levantine' => ['label' => 'شامية', 'direction' => 'Levantine Arabic (Syrian/Lebanese) accent.'],
        'maghrebi' => ['label' => 'مغربية', 'direction' => 'Moroccan Arabic (Darija) accent.'],
        'iraqi' => ['label' => 'عراقية', 'direction' => 'Iraqi Arabic (Baghdadi) accent.'],
        'sudanese' => ['label' => 'سودانية', 'direction' => 'Sudanese Arabic accent.'],
    ],

    'default_dialect' => 'auto',

    'accents' => [
        'american' => ['label' => 'أمريكية', 'en' => 'American', 'direction' => 'General American English accent.'],
        'british' => ['label' => 'بريطانية', 'en' => 'British', 'direction' => 'Standard British English (RP) accent.'],
        'australian' => ['label' => 'أسترالية', 'en' => 'Australian', 'direction' => 'Australian English accent.'],
        'indian' => ['label' => 'هندية', 'en' => 'Indian', 'direction' => 'Indian English accent.'],
    ],

    'default_accent' => 'american',

    /*
    | عينة «استمع» لكل مذيع: تُولَّد مرة وتُحفظ لكل المتاجر. :name يُستبدل باسم المذيع.
    */
    'samples' => [
        'ar' => 'هلا والله! معك :name، وهذا صوتي. خلّني أحكي قصة متجرك بأسلوب يشبهك ويوصل لعملائك.',
        'en' => "Hi, I'm :name. Let me tell your store's story in a voice that sounds just like you.",
        'direction' => 'A friendly, natural introduction with a warm smile.',
        // نموذج العينات: الأسرع يكفي لثوانٍ معدودة
        'tier' => 'standard',
    ],

    /*
    | وسوم الأداء التي يضيفها «تحسين الصوت» ويفهمها النموذج دون أن ينطقها.
    | ما عداها يُحذف من النص قبل تسليمه.
    */
    'performance_tags' => [
        'short pause', 'medium pause', 'long pause',
        'excited', 'cheerful', 'curious', 'serious', 'warm', 'whispering', 'laughing',
    ],

    // أسماء مختصرة للأهداف في بطاقات «محتوى سابق» (الاسم الكامل في content.goals)
    'goal_tags' => [
        'direct_sales' => 'زيادة مبيعات',
        'followers' => 'زيادة متابعين',
        'engagement' => 'زيادة تفاعل',
        'reposts' => 'زيادة ريبوست',
        'views' => 'زيادة مشاهدات',
        'reach' => 'زيادة وصول',
        'shares' => 'زيادة مشاركات',
        'reputation' => 'بناء سمعة',
        'positioning' => 'تموقع بالسوق',
        'competitive_edge' => 'ميزة تنافسية',
        'trust' => 'بناء ثقة',
    ],
];
