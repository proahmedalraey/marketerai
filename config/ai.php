<?php

return [

    /*
    |--------------------------------------------------------------------------
    | المزودون الافتراضيون
    |--------------------------------------------------------------------------
    | لا تستدعِ أي مزود مباشرة من الكود. كل شيء يمر عبر AiManager،
    | حتى يمكن تبديل المزود أو النموذج دون لمس منطق التطبيق.
    */

    /*
    |--------------------------------------------------------------------------
    | قرص تخزين الوسائط المولّدة
    |--------------------------------------------------------------------------
    | يجب أن يكون قرصاً يدعم توليد الروابط. القرص local لا يدعمها،
    | فاستخدام public محلياً و s3/r2 في الإنتاج.
    */

    'media_disk' => env('MEDIA_DISK', 'public'),

    'text_provider' => env('AI_TEXT_PROVIDER', 'fake'),
    'image_provider' => env('AI_IMAGE_PROVIDER', 'fake'),

    'providers' => [

        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => env('ANTHROPIC_TEXT_MODEL', 'claude-sonnet-4-5'),
            'max_tokens' => 4096,
            'timeout' => 120,
        ],

        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_TEXT_MODEL', 'gpt-4.1'),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
            'max_tokens' => 4096,
            'timeout' => 180,
        ],

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            // 2.5 لم يعد متاحاً للحسابات الجديدة؛ القائمة الحية في صفحة الإعدادات تعرض المتاح لمفتاحك
            'model' => env('GEMINI_TEXT_MODEL', 'gemini-3.6-flash'),
            'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image'),
            // أعلى من غيره: تفكير نماذج Gemini يُحسب من نفس سقف المخرجات.
            // 8192 لم يكفِ: كاروسيل استهلكه كله تفكيراً فانقطع JSON في منتصفه (سجل 2026-09-22)
            'max_tokens' => 16384,
            // low | high | off. كتابة تسويقية لا تحتاج تفكيراً عميقاً، وبوابة الصدق تلتقط الأخطاء؛
            // التفكير المنخفض أسرع (44 ثانية كانت لكاروسيل واحد) وأرخص في الخطة المدفوعة
            'thinking' => env('GEMINI_THINKING', 'low'),
            'timeout' => 180,
        ],

        'fake' => [
            'driver' => 'fake',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | إعادة المحاولة
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | مهلة المهمة العالقة
    |--------------------------------------------------------------------------
    | مهمة لم تتقدم منذ هذه المدة تُنهى وتُرجع نقاطها (ai:reap-stuck-jobs).
    | أطول بكثير من أبطأ توليد طبيعي، حتى لا نُسقط مهمة كانت ستنجح.
    */

    'stuck_after_minutes' => (int) env('AI_STUCK_AFTER_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | التدقيق اللغوي التلقائي
    |--------------------------------------------------------------------------
    | يمر على كل منشور بعد بوابة الصدق ويصحح الإملاء والنحو فقط، مجاناً للتاجر.
    | provider: مزود أرخص للتدقيق إن وُجد؛ فارغ = مزود النص الافتراضي.
    | حدود التعديل تمنع المدقق من إعادة الصياغة: الحقل الذي يتجاوزها يُترك كما هو.
    */

    'proofread' => [
        'enabled' => (bool) env('AI_PROOFREAD', true),
        'provider' => env('AI_PROOFREAD_PROVIDER') ?: null,
        // نسبة الكلمات المتغيرة في الحقل (مع حد أدنى كلمتان للشرائح القصيرة)
        'max_changed_share' => 0.2,
        // أطول تعديل واحد بالكلمات: الإملاء كلمة، والنحو كلمتان أو ثلاث
        'max_edit_words' => 3,
    ],

    'retry' => [
        'times' => 2,
        'sleep_ms' => 1500,
    ],

    /*
    |--------------------------------------------------------------------------
    | نسب الأبعاد المدعومة في توليد الصور
    |--------------------------------------------------------------------------
    */

    'aspect_ratios' => [
        '1:1' => ['w' => 1024, 'h' => 1024, 'label' => 'مربع · منشور', 'group' => 'square'],
        '4:5' => ['w' => 1024, 'h' => 1280, 'label' => 'عمودي · إنستغرام', 'group' => 'vertical'],
        '9:16' => ['w' => 1024, 'h' => 1820, 'label' => 'ستوري وريلز', 'group' => 'vertical'],
        '16:9' => ['w' => 1820, 'h' => 1024, 'label' => 'عريض · يوتيوب', 'group' => 'horizontal'],
        '3:4' => ['w' => 1024, 'h' => 1365, 'label' => 'عمودي خفيف', 'group' => 'vertical'],

        // نسب استوديو الصور الجديد (§ب في docs/image-studio-redesign-plan.md) — إضافية لا تمس القديمة أعلاه.
        '3:2' => ['w' => 1536, 'h' => 1024, 'label' => '3:2', 'group' => 'horizontal'],
        '4:3' => ['w' => 1365, 'h' => 1024, 'label' => '4:3', 'group' => 'horizontal'],
        '9:8' => ['w' => 1152, 'h' => 1024, 'label' => '9:8', 'group' => 'horizontal'],
        '21:9' => ['w' => 2389, 'h' => 1024, 'label' => '21:9', 'group' => 'horizontal'],
        '27:16' => ['w' => 1728, 'h' => 1024, 'label' => '27:16', 'group' => 'horizontal'],
        '8:9' => ['w' => 1024, 'h' => 1152, 'label' => '8:9', 'group' => 'vertical'],
        '16:27' => ['w' => 1024, 'h' => 1728, 'label' => '16:27', 'group' => 'vertical'],
        '2:3' => ['w' => 1024, 'h' => 1536, 'label' => '2:3', 'group' => 'vertical'],
    ],

    'quality_tiers' => [
        'standard_1k' => ['label' => '1K · جودة متوسطة', 'size' => 1024, 'credits' => 1],
        'high_1k' => ['label' => '1K · جودة ممتازة', 'size' => 1024, 'credits' => 4],
        'standard_2k' => ['label' => '2K · جودة متوسطة', 'size' => 2048, 'credits' => 2],
        'high_2k' => ['label' => '2K · جودة ممتازة', 'size' => 2048, 'credits' => 14],
    ],

    /*
    |--------------------------------------------------------------------------
    | مصفوفة الدقة × الجودة — استوديو الصور الجديد فقط
    |--------------------------------------------------------------------------
    | تحل محل quality_tiers في /studio فقط (تحقق ImageStudioController::store()).
    | صفحة الكاروسيل (content/show.blade.php) تبقى على quality_tiers القديمة.
    | المفتاح المركّب "{resolution}_{quality}" يصل كما هو إلى ImageGenerationService
    | ويُبنى منه مفتاح تكلفة "image.{quality}" في config/credits.php دون أي تغيير
    | في منطق الخدمة نفسها.
    */

    'resolutions' => [
        '1k' => ['label' => '1K', 'hint' => 'حتى 1024 بكسل', 'size' => 1024],
        '2k' => ['label' => '2K', 'hint' => 'حتى 2048 بكسل', 'size' => 2048],
        '4k' => ['label' => '4K', 'hint' => 'حتى 3840 بكسل', 'size' => 3840],
    ],

    'quality_levels' => [
        'low' => ['label' => 'جودة منخفضة', 'hint' => 'مسودات وتجربة أفكار بسرعة وبأقل تكلفة'],
        'medium' => ['label' => 'جودة متوسطة', 'hint' => 'متوازنة للمنشورات والاستخدام اليومي'],
        'high' => ['label' => 'جودة عالية', 'hint' => 'تفاصيل أوضح ونصوص أدق لصور المنتجات'],
        'very_high' => ['label' => 'جودة عالية جداً', 'hint' => 'دقة متقدمة للإعلانات والصور المصقولة'],
        'max' => ['label' => 'جودة قصوى', 'hint' => 'أعلى جودة ممكنة للحملات — الأبطأ'],
    ],

    'image_quality_matrix' => [
        '1k_low' => ['resolution' => '1k', 'level' => 'low', 'size' => 1024, 'credits' => 0.5],
        '1k_medium' => ['resolution' => '1k', 'level' => 'medium', 'size' => 1024, 'credits' => 1],
        '1k_high' => ['resolution' => '1k', 'level' => 'high', 'size' => 1024, 'credits' => 4],
        '1k_very_high' => ['resolution' => '1k', 'level' => 'very_high', 'size' => 1024, 'credits' => 7],
        '1k_max' => ['resolution' => '1k', 'level' => 'max', 'size' => 1024, 'credits' => 16],
        '2k_low' => ['resolution' => '2k', 'level' => 'low', 'size' => 2048, 'credits' => 1],
        '2k_medium' => ['resolution' => '2k', 'level' => 'medium', 'size' => 2048, 'credits' => 2],
        '2k_high' => ['resolution' => '2k', 'level' => 'high', 'size' => 2048, 'credits' => 8],
        '2k_very_high' => ['resolution' => '2k', 'level' => 'very_high', 'size' => 2048, 'credits' => 14.5],
        '2k_max' => ['resolution' => '2k', 'level' => 'max', 'size' => 2048, 'credits' => 32],
        '4k_low' => ['resolution' => '4k', 'level' => 'low', 'size' => 3840, 'credits' => 1.5],
        '4k_medium' => ['resolution' => '4k', 'level' => 'medium', 'size' => 3840, 'credits' => 3.5],
        '4k_high' => ['resolution' => '4k', 'level' => 'high', 'size' => 3840, 'credits' => 13.5],
        '4k_very_high' => ['resolution' => '4k', 'level' => 'very_high', 'size' => 3840, 'credits' => 23.5],
        '4k_max' => ['resolution' => '4k', 'level' => 'max', 'size' => 3840, 'credits' => 53.5],
    ],

    /*
    |--------------------------------------------------------------------------
    | نماذج استوديو الصور المعروضة — شكلية حالياً (قرار §2 في الجلسة)
    |--------------------------------------------------------------------------
    | التبديل بينها في الواجهة لا يغيّر المزوّد الفعلي؛ التوليد يستخدم دوماً
    | image_provider أعلاه. التوجيه الفعلي موثّق في docs/image-studio-redesign-plan.md.
    */

    'studio_models' => [
        'nano_banana_2' => ['label' => 'Nano Banana 2', 'hint' => 'متوازن · حتى 4K'],
        'gpt_image_sunburst' => ['label' => 'GPT Image 2.5 – Sunburst', 'hint' => 'أعلى دقة، للحملات والصور المصقولة'],
        'gpt_image_flare' => ['label' => 'GPT Image 2.5 – Flare', 'hint' => 'سريع ومتوازن، للاستخدام اليومي'],
        'grok_imagine' => ['label' => 'Grok Imagine', 'hint' => 'سريع'],
        'grok_imagine_2' => ['label' => 'Grok Imagine 2.0', 'hint' => 'دقة أعلى، أبطأ'],
    ],

    'studio_default_model' => 'gpt_image_flare',
];
