<?php

/*
|--------------------------------------------------------------------------
| التعليق الصوتي (Beta)
|--------------------------------------------------------------------------
| كل ما يغيّر الإلقاء هنا لا في الكود: المذيعون وأصواتهم الفعلية عند المزود،
| وأنماط الإلقاء وتوجيهها، واللهجات، ومستويات الجودة ونماذجها.
|
| التوجيهات (direction) بالإنجليزية عمداً: نماذج Gemini TTS تلتزم بها أدق من العربية
| (ملاحظات عربية تجاهلها gemini-3.8-flash-tts في مراجعة 2026-09-29).
|
| كل توجيه «عبارة» تُكمل جملة واحدة يبنيها VoiceCatalog::direction():
|   Read aloud <اللهجة>, <الأسلوب>:
|   <النص>
| لا نقطتان داخل العبارة ولا جمل مستقلة: الصيغة الطويلة بعناوين كان flash-lite ينطقها.
*/

return [

    // حد النص الواحد: نحو دقيقتين ونصف من الكلام، وما فوقه يُقسَّم على أكثر من تعليق
    'max_chars' => 2000,

    // ما يُنطق في الدقيقة لتقدير الحجز قبل التوليد. أقل من المعتاد (~140) عمداً:
    // الحجز الزائد يُرجَع عند التسوية، والناقص يتحمله المتجر لا التاجر.
    'words_per_minute' => 120,

    /*
    | حارس المدة: تسجيل أطول بكثير من نصه يعني أن النموذج قرأ التوجيه بصوت عالٍ أو كرر النص
    | (رُصد 2026-09-29 على gemini-3.8-flash-lite-tts: 18 ثانية لجملة مدتها 8). يُعاد مرة، فإن بقي
    | طويلاً فشلت المهمة وأُرجعت النقاط. ~2 كلمة/ثانية مقيسة على المذيعين بالعربية.
    */
    'overlong' => [
        'words_per_second' => 2.0,
        'factor' => 1.8,
        'grace_seconds' => 3,
    ],

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
            // لا gemini-3.8-flash-lite-tts: في مراجعة 2026-09-29 قرأ التوجيه أو كرر النص في 6 من 8 تسجيلات،
            // و2.5-flash-preview سجّل 4 من 4 نظيفة. يُبدَّل من صفحة الإعدادات.
            'model' => env('VOICEOVER_STANDARD_MODEL', 'gemini-2.5-flash-preview-tts'),
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
    | المذيعون: الاسم للتاجر، والصوت الفعلي عند المزود (أصوات Gemini الجاهزة)، وطابعه
    | بالعربية للواجهة (tone) وبالإنجليزية لعينة «استمع» (character).
    | صورة كل مذيع في public/images/voices/<key>.jpg (voiceover:avatars) — بدونها يظهر الحرف الأول.
    */
    'voices' => [
        // ذكور
        'khalid' => ['name' => 'خالد', 'en' => 'Khalid', 'gender' => 'male', 'provider_voice' => 'Charon', 'tone' => 'رزين ومعلوماتي', 'character' => 'informative and composed'],
        'mutaib' => ['name' => 'متعب', 'en' => 'Mutaib', 'gender' => 'male', 'provider_voice' => 'Orus', 'tone' => 'حازم وواثق', 'character' => 'firm and confident'],
        'ahmed' => ['name' => 'أحمد', 'en' => 'Ahmed', 'gender' => 'male', 'provider_voice' => 'Iapetus', 'tone' => 'واضح ومباشر', 'character' => 'clear and direct'],
        'hani' => ['name' => 'هاني', 'en' => 'Hani', 'gender' => 'male', 'provider_voice' => 'Puck', 'tone' => 'مرح ومتفائل', 'character' => 'upbeat and cheerful'],
        'faris' => ['name' => 'فارس', 'en' => 'Faris', 'gender' => 'male', 'provider_voice' => 'Fenrir', 'tone' => 'حماسي ومندفع', 'character' => 'excited and energetic'],
        'waleed' => ['name' => 'وليد', 'en' => 'Waleed', 'gender' => 'male', 'provider_voice' => 'Achird', 'tone' => 'ودود وقريب', 'character' => 'friendly and approachable'],
        'sultan' => ['name' => 'سلطان', 'en' => 'Sultan', 'gender' => 'male', 'provider_voice' => 'Algieba', 'tone' => 'ناعم وسلس', 'character' => 'smooth and polished'],
        'abdulrahman' => ['name' => 'عبدالرحمن', 'en' => 'Abdulrahman', 'gender' => 'male', 'provider_voice' => 'Sadaltager', 'tone' => 'خبير وموثوق', 'character' => 'knowledgeable and trustworthy'],
        'mansour' => ['name' => 'منصور', 'en' => 'Mansour', 'gender' => 'male', 'provider_voice' => 'Algenib', 'tone' => 'عميق وأجش', 'character' => 'deep and gravelly'],

        // إناث
        'hadeel' => ['name' => 'هديل', 'en' => 'Hadeel', 'gender' => 'female', 'provider_voice' => 'Kore', 'tone' => 'حازمة وواثقة', 'character' => 'firm and confident'],
        'sara' => ['name' => 'سارة', 'en' => 'Sara', 'gender' => 'female', 'provider_voice' => 'Zephyr', 'tone' => 'مشرقة ومبهجة', 'character' => 'bright and joyful'],
        'reem' => ['name' => 'ريم', 'en' => 'Reem', 'gender' => 'female', 'provider_voice' => 'Aoede', 'tone' => 'خفيفة ومنسابة', 'character' => 'breezy and light'],
        'huda' => ['name' => 'هدى', 'en' => 'Huda', 'gender' => 'female', 'provider_voice' => 'Sulafat', 'tone' => 'دافئة وحنونة', 'character' => 'warm and caring'],
        'noura' => ['name' => 'نورة', 'en' => 'Noura', 'gender' => 'female', 'provider_voice' => 'Leda', 'tone' => 'شابة وحيوية', 'character' => 'youthful and lively'],
        'dana' => ['name' => 'دانة', 'en' => 'Dana', 'gender' => 'female', 'provider_voice' => 'Despina', 'tone' => 'ناعمة وسلسة', 'character' => 'smooth and soft'],
        'ghada' => ['name' => 'غادة', 'en' => 'Ghada', 'gender' => 'female', 'provider_voice' => 'Gacrux', 'tone' => 'ناضجة ورزينة', 'character' => 'mature and composed'],
        'jouri' => ['name' => 'جوري', 'en' => 'Jouri', 'gender' => 'female', 'provider_voice' => 'Laomedeia', 'tone' => 'مرحة ونشيطة', 'character' => 'upbeat and playful'],
        'aisha' => ['name' => 'عائشة', 'en' => 'Aisha', 'gender' => 'female', 'provider_voice' => 'Vindemiatrix', 'tone' => 'هادئة ولطيفة', 'character' => 'gentle and calm'],
    ],

    'default_voice' => 'hadeel',

    /*
    | صور المذيعين (voiceover:avatars): صورة استوديو واحدة لكل مذيع تطابق طابع صوته.
    | أشخاص متخيَّلون يولّدهم نموذج الصور — تُحفظ في public/images/voices/<key>.jpg وتُرفع مع الكود.
    */
    'avatars' => [
        'model' => env('VOICEOVER_AVATAR_MODEL', 'google/gemini-3.1-flash-image'),
        'size' => 320,
        'style' => 'Professional studio headshot portrait photograph, head and shoulders, centered and facing the camera, '
            .'soft even studio lighting, plain soft light-gray background, photorealistic, natural skin texture, sharp focus, '
            .'square composition. No text, no letters, no logo, no watermark.',
        'looks' => [
            'khalid' => 'a Saudi man in his late 30s with a neat short black beard, wearing a white ghutra and a white thobe, calm and composed expression',
            'mutaib' => 'a Saudi man in his 30s with a trimmed beard, wearing a red-and-white checkered shemagh and a white thobe, confident firm expression',
            'ahmed' => 'a clean-shaven Arab man in his late 20s with short dark hair, wearing a navy suit, white shirt and tie, clear direct gaze',
            'hani' => 'a cheerful young Saudi man in his mid 20s with a big warm smile and a light beard, wearing a red shemagh and a white thobe',
            'faris' => 'an energetic Arab man in his early 30s with styled dark hair and a short beard, wearing a black casual shirt, lively excited expression',
            'waleed' => 'a friendly Saudi man in his 30s with a short beard, wearing a white ghutra and thobe, warm approachable smile',
            'sultan' => 'a polished Gulf man in his 40s with a groomed beard, wearing a crisp white ghutra with a black agal and a white thobe, smooth composed expression',
            'abdulrahman' => 'an Arab man in his early 30s with neat hair and a short beard, wearing a white shirt and a grey blazer, knowledgeable trustworthy expression',
            'mansour' => 'a Saudi man in his late 50s with a full grey beard, wearing a white ghutra and thobe, deep wise expression',
            'hadeel' => 'a confident Saudi woman in her 30s wearing a light grey hijab and a black abaya, firm composed expression',
            'sara' => 'a joyful Arab woman in her late 20s wearing a beige hijab, bright warm smile',
            'reem' => 'a young Arab woman in her mid 20s wearing a soft pink hijab, light relaxed smile',
            'huda' => 'a warm Arab woman in her 30s wearing a black hijab, caring gentle smile',
            'noura' => 'a lively young Saudi woman in her early 20s wearing a colorful patterned hijab, youthful bright expression',
            'dana' => 'an Arab woman in her 30s wearing a cream hijab, soft calm expression',
            'ghada' => 'an elegant mature Arab woman in her late 40s wearing a dark navy hijab, composed thoughtful expression',
            'jouri' => 'a playful young Arab woman in her early 20s wearing a bright teal hijab, cheerful expression',
            'aisha' => 'a gentle Arab woman in her 30s wearing a light blue hijab, calm serene expression',
        ],
    ],

    /*
    | أنماط الإلقاء. custom يأخذ ملاحظات التاجر بعد ترجمتها لعبارة إنجليزية
    | (VoiceScriptTools::englishDirection) بدل direction.
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
            'direction' => 'light-hearted and playful, with a smile in the voice and comic timing on the punchlines, never mocking',
        ],
        'news' => [
            'label' => 'خبر وتقرير',
            'icon' => 'newspaper',
            'hint' => 'رصين كنشرة إخبارية',
            'direction' => 'like a composed news anchor, clear, measured, authoritative and neutral, with crisp articulation and steady pacing',
        ],
        'story' => [
            'label' => 'سرد قصصي',
            'icon' => 'book-open',
            'hint' => 'يحكي بتشويق وهدوء',
            'direction' => 'like a warm storyteller, intimate and engaging, building gentle suspense with natural pauses before key moments',
        ],
        'pitch' => [
            'label' => 'عرض تشجيعي',
            'icon' => 'megaphone',
            'hint' => 'متحمس ومحفّز',
            'direction' => 'like an inspiring motivational presenter, confident, uplifting and energetic, emphasising benefits and ending with conviction',
        ],
        'podcast' => [
            'label' => 'بودكاست',
            'icon' => 'podcast',
            'hint' => 'حواري وطبيعي',
            'direction' => 'like a relaxed podcast host talking to a friend, conversational, natural and unhurried, with casual warmth',
        ],
        'reels' => [
            'label' => 'ريلز وتيك توك',
            'icon' => 'reel',
            'hint' => 'سريع وجذاب للفيديو القصير',
            'direction' => 'like an energetic Reels and TikTok creator, punchy, upbeat and fast-paced, with a strong hook in the first words',
        ],
        'product_ad' => [
            'label' => 'إعلان منتج',
            'icon' => 'shopping-bag',
            'hint' => 'إعلاني واضح ومقنع',
            'direction' => 'like a polished commercial voice-over, persuasive, bright and trustworthy, with a confident call to action',
        ],
    ],

    'default_style' => 'reels',

    /*
    | اللهجات العربية. variants لهجات فرعية تظهر تحت أمها (سعودية ← بيضاء/حجازية/نجدية).
    */
    'dialects' => [
        'auto' => [
            'label' => 'تلقائي',
            'direction' => 'in the Arabic dialect the text is written in (Modern Standard Arabic stays standard)',
        ],
        'msa' => [
            'label' => 'فصحى',
            'direction' => 'in Modern Standard Arabic (fusha) with correct, clear pronunciation',
        ],
        'saudi' => [
            'label' => 'سعودية',
            'direction' => 'in Saudi Arabic',
            'variants' => [
                'white' => ['label' => 'بيضاء', 'direction' => 'in neutral white Saudi and Gulf Arabic, easily understood across the Gulf'],
                'hijazi' => ['label' => 'حجازية', 'direction' => 'in Hijazi Saudi Arabic (Jeddah and Makkah accent)'],
                'najdi' => ['label' => 'نجدية (الرياض)', 'direction' => 'in Najdi Saudi Arabic (Riyadh accent)'],
            ],
            'default_variant' => 'white',
        ],
        'egyptian' => ['label' => 'مصرية', 'direction' => 'in Egyptian Arabic (Cairene accent)'],
        'levantine' => ['label' => 'شامية', 'direction' => 'in Levantine Arabic (Syrian and Lebanese accent)'],
        'maghrebi' => ['label' => 'مغربية', 'direction' => 'in Moroccan Arabic (Darija)'],
        'iraqi' => ['label' => 'عراقية', 'direction' => 'in Iraqi Arabic (Baghdadi accent)'],
        'sudanese' => ['label' => 'سودانية', 'direction' => 'in Sudanese Arabic'],
    ],

    'default_dialect' => 'auto',

    'accents' => [
        'american' => ['label' => 'أمريكية', 'en' => 'American', 'direction' => 'in English with a General American accent'],
        'british' => ['label' => 'بريطانية', 'en' => 'British', 'direction' => 'in English with a standard British (RP) accent'],
        'australian' => ['label' => 'أسترالية', 'en' => 'Australian', 'direction' => 'in English with an Australian accent'],
        'indian' => ['label' => 'هندية', 'en' => 'Indian', 'direction' => 'in English with an Indian English accent'],
    ],

    'default_accent' => 'american',

    /*
    | عينة «استمع» لكل مذيع: تُولَّد مرة وتُحفظ لكل المتاجر. :name يُستبدل باسم المذيع.
    */
    'samples' => [
        'ar' => 'هلا والله! معك :name، وهذا صوتي. خلّني أحكي قصة متجرك بأسلوب يشبهك ويوصل لعملائك.',
        'en' => "Hi, I'm :name. Let me tell your store's story in a voice that sounds just like you.",
        // يُضاف إليه طابع المذيع (character): «…, informative and composed, as a friendly…»
        'direction' => 'as a friendly, natural self-introduction with a warm smile',
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
