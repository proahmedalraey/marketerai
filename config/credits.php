<?php

return [

    /*
    |--------------------------------------------------------------------------
    | حصة الاشتراك
    |--------------------------------------------------------------------------
    */

    'monthly_allowance' => (int) env('CREDITS_MONTHLY_ALLOWANCE', 1000),

    /*
    | سقف يومي ضمني يحمي من الاستنزاف وإعادة بيع الحساب.
    | اجعله null لتعطيله.
    */
    'daily_cap' => env('CREDITS_DAILY_CAP') !== null ? (int) env('CREDITS_DAILY_CAP') : null,

    /*
    |--------------------------------------------------------------------------
    | تكلفة كل عملية بالنقاط
    |--------------------------------------------------------------------------
    | التكلفة الحقيقية للمزود تُسجل في ai_usage_logs بالدولار.
    | هذه القيم هي ما يراه المستخدم، وتتغير دون أن تمس أسعار الباقات.
    */

    'costs' => [
        'content.post' => 1,
        'content.carousel' => 2,
        'content.reel_script' => 2,
        'content.story' => 2,
        'content.infographic' => 2,
        'content.batch_item' => 1,
        // إعادة كتابة شريحة واحدة أو تحسينها بتوجيه (قرار §9-2 في خطة التدقيق)
        'content.slide_regen' => 1,
        'product.spec_sheet' => 1,
        'brand.profile' => 2,
        'brand.prefill' => 1,
        'brand.vision_extract' => 1,
        'image.standard_1k' => 1,
        'image.high_1k' => 4,
        'image.standard_2k' => 2,
        'image.high_2k' => 14,

        // مصفوفة استوديو الصور الجديدة (دقة × جودة) — تحل محل الأربعة أعلاه في /studio فقط.
        // القديمة تبقى لصفحة الكاروسيل (content/show.blade.php) دون مساس.
        'image.1k_low' => 0.5,
        'image.1k_medium' => 1,
        'image.1k_high' => 4,
        'image.1k_very_high' => 7,
        'image.1k_max' => 16,
        'image.2k_low' => 1,
        'image.2k_medium' => 2,
        'image.2k_high' => 8,
        'image.2k_very_high' => 14.5,
        'image.2k_max' => 32,
        'image.4k_low' => 1.5,
        'image.4k_medium' => 3.5,
        'image.4k_high' => 13.5,
        'image.4k_very_high' => 23.5,
        'image.4k_max' => 53.5,

        // إزالة خلفية صورة من المعرض (نسخة شفافة بجانب الأصل) — طلب صورة واحد 1K
        'image.remove_bg' => 1,

        // تحسين وصف الصورة بالذكاء (زر العصا في /studio): طلب نصي صغير جداً، مجاني افتراضياً.
        // اجعله 0.1 أو أكثر إن أردت تسعيره؛ 0 يعني بلا حجز ولا سجل نقاط، والمهمة تبقى في الطابور.
        'prompt.enhance' => 0,

        'voice.minute' => 2,
        'video.short' => 20,
    ],
];
