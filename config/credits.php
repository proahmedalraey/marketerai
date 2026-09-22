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
        'voice.minute' => 2,
        'video.short' => 20,
    ],
];
