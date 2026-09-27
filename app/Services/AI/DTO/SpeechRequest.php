<?php

namespace App\Services\AI\DTO;

class SpeechRequest
{
    public function __construct(
        /** النص الذي يُنطق حرفياً */
        public string $transcript,
        /** اسم الصوت عند المزود (Kore، Charon…) */
        public string $voice,
        /** توجيه الأداء: الأسلوب واللهجة. لا يُنطق */
        public string $direction = '',
        /** نموذج لهذا الطلب وحده (مستوى الجودة) بدل نموذج المزود الافتراضي */
        public ?string $model = null,
        public string $operation = 'voice.speech',
    ) {}
}
