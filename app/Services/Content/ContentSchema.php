<?php

namespace App\Services\Content;

/**
 * المخرج منظم دائماً. لا نحلل نصاً حراً.
 * هذا وحده يلغي فئة كاملة من أخطاء الإنتاج.
 */
class ContentSchema
{
    /**
     * @param  array{count?: array{0: int, 1: int}, filming?: bool}  $shape  ما يفرضه شكل المحتوى:
     *         عدد المشاهد أو الإطارات أو التغريدات، وسكربت التصوير.
     */
    public static function for(string $format, int $slideCount = 0, array $shape = []): array
    {
        [$min, $max] = $shape['count'] ?? [0, 0];
        $filming = (bool) ($shape['filming'] ?? false);
        $count = fn (int $fallbackMin, int $fallbackMax) => array_filter([
            'minItems' => $min ?: $fallbackMin,
            'maxItems' => $max ?: $fallbackMax,
        ]);

        $shot = ['type' => 'string', 'description' => 'توجيه التصوير لمن يصوّر: نوع اللقطة وزاويتها وحركتها، والمكان والإضاءة وما يظهر فيها'];

        return match ($format) {
            'carousel' => [
                'type' => 'object',
                'required' => ['slides', 'caption', 'hashtags'],
                'properties' => [
                    'slides' => [
                        'type' => 'array',
                        'minItems' => max($slideCount, 3),
                        'maxItems' => max($slideCount, 3),
                        'items' => [
                            'type' => 'object',
                            'required' => ['role', 'text', 'visual'],
                            'properties' => [
                                'role' => ['type' => 'string', 'enum' => ['hook', 'promise', 'pull', 'harvest', 'ask']],
                                'text' => ['type' => 'string', 'description' => 'نص الشريحة، من 8 إلى 35 كلمة'],
                                // الهوك ثلاث طبقات بثلاثة أحجام على الصورة (تدقيق المنافس C6/C10)
                                'kicker' => ['type' => 'string', 'description' => 'للهوك فقط: تمهيد من كلمتين إلى أربع، يُكتب صغيراً'],
                                'focal' => ['type' => 'string', 'description' => 'للهوك فقط: العبارة المحورية من كلمة إلى ثلاث، تُكتب كبيرة بلون مميز'],
                                'tail' => ['type' => 'string', 'description' => 'للهوك فقط: تكملة قصيرة. kicker ثم focal ثم tail = text'],
                                'visual' => ['type' => 'string', 'description' => 'وصف صورة هذه الشريحة بالإنجليزية، بلا أي نص داخلها، مع مساحة هادئة في أعلاها للعنوان'],
                            ],
                        ],
                    ],
                    'caption' => ['type' => 'string', 'description' => 'كابشن المنشور تحت الكاروسيل'],
                    'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],

            // التوقيت حقل مستقل لا داخل الكلام: «0-3» ليست رقماً عن المنتج يُحاسب عليه الفحص
            'reel' => [
                'type' => 'object',
                'required' => ['hook', 'scenes', 'caption', 'hashtags'],
                'properties' => [
                    'hook' => ['type' => 'string', 'description' => 'الجملة الأولى في أول ثانيتين'],
                    'scenes' => [
                        'type' => 'array',
                        ...$count(3, 8),
                        'items' => [
                            'type' => 'object',
                            'required' => $filming ? ['time', 'voiceover', 'on_screen', 'shot'] : ['time', 'voiceover', 'on_screen'],
                            'properties' => array_filter([
                                'time' => ['type' => 'string', 'description' => 'توقيت المشهد بالثواني من بداية الفيديو، مثل 0-3'],
                                'voiceover' => ['type' => 'string', 'description' => 'ما يُقال في هذا المشهد'],
                                'on_screen' => ['type' => 'string', 'description' => 'نص قصير يظهر على الشاشة، أو فارغ'],
                                'shot' => $filming ? $shot : null,
                            ]),
                        ],
                    ],
                    'caption' => ['type' => 'string', 'description' => 'الوصف الذي يُنشر مع الفيديو'],
                    'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],

            'story' => [
                'type' => 'object',
                'required' => ['frames'],
                'properties' => [
                    'frames' => [
                        'type' => 'array',
                        ...$count(1, 7),
                        'items' => [
                            'type' => 'object',
                            'required' => $filming ? ['text', 'visual', 'shot'] : ['text', 'visual'],
                            'properties' => array_filter([
                                'text' => ['type' => 'string', 'description' => 'النص الظاهر على الإطار، قصير يُقرأ في ثوانٍ'],
                                'visual' => ['type' => 'string', 'description' => 'ما يظهر في الإطار: صورة أو لقطة فيديو'],
                                'interaction' => ['type' => 'string', 'description' => 'ملصق تفاعلي إن ناسب هذا الإطار (استفتاء أو سؤال أو رابط) مع نصه، أو فارغ'],
                                'shot' => $filming ? $shot : null,
                            ]),
                        ],
                    ],
                ],
            ],

            'thread' => [
                'type' => 'object',
                'required' => ['tweets', 'hashtags'],
                'properties' => [
                    'tweets' => [
                        'type' => 'array',
                        ...$count(2, 6),
                        'items' => ['type' => 'string', 'description' => 'تغريدة واحدة لا تتجاوز 280 حرفاً'],
                    ],
                    'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],

            'infographic' => [
                'type' => 'object',
                'required' => ['title', 'sections', 'caption', 'hashtags'],
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'عنوان الإنفوجرافيك'],
                    'sections' => [
                        'type' => 'array',
                        'minItems' => 4,
                        'maxItems' => 6,
                        'items' => [
                            'type' => 'object',
                            'required' => ['heading', 'text'],
                            'properties' => [
                                'heading' => ['type' => 'string', 'description' => 'عنوان القسم من كلمتين إلى أربع'],
                                'text' => ['type' => 'string', 'description' => 'سطر واحد قصير'],
                            ],
                        ],
                    ],
                    'caption' => ['type' => 'string', 'description' => 'الوصف الذي يُنشر مع الإنفوجرافيك'],
                    'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'image_prompts' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],

            default => [
                'type' => 'object',
                'required' => ['caption', 'hashtags'],
                'properties' => [
                    'caption' => ['type' => 'string', 'description' => 'نص المنشور كاملاً'],
                    'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'image_prompts' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        };
    }

    /**
     * تطبيع مخرج النموذج إلى بنية ثابتة يعتمد عليها باقي النظام.
     *
     * الهاشتاقات تُقص عند حد المنصة: البرومبت يطلب الحد لكن النموذج
     * لا يلتزم دائماً (سبعة في منشور وصفر في آخر عند المنافس، C6).
     */
    public static function normalize(?array $data, string $format, int $expectedSlides = 0, ?string $platform = null): array
    {
        $data ??= [];
        $maxTags = $platform ? (int) config("content.platforms.{$platform}.hashtags.max", 10) : 10;
        $clean = fn ($value) => trim((string) $value);

        $slides = collect($data['slides'] ?? [])
            ->map(fn ($slide) => self::slide(is_array($slide) ? $slide : ['text' => (string) $slide]))
            ->filter(fn ($slide) => $slide['text'] !== '')
            ->values()
            ->all();

        $hashtags = collect($data['hashtags'] ?? [])
            // حروف لاتينية ملتصقة بآخر وسم عربي أثرٌ من النموذج لا جزء منه
            // («بن_الديرةSend» في تقييم 2026-09-22). «قهوة_V60» تبقى: الفاصل يدل على القصد
            ->map(fn ($tag) => preg_replace('/(?<=\p{Arabic})[A-Za-z]+$/u', '', ltrim(trim((string) $tag), '#')))
            ->filter()
            ->unique()
            ->take($maxTags)
            ->values()
            ->all();

        $base = [
            'caption' => $clean($data['caption'] ?? ''),
            'slides' => $format === 'carousel' ? $slides : [],
            'script' => isset($data['script']) ? $clean($data['script']) : null,
            'hook' => isset($data['hook']) ? $clean($data['hook']) : null,
            'hashtags' => $hashtags,
            'image_prompts' => array_values(array_filter(array_map($clean, $data['image_prompts'] ?? []))),
        ];

        // الحقول الفارغة لا تُحفظ: «shot» بلا طلب تصوير، و«interaction» في إطار بلا ملصق
        $row = fn (array $item, array $keys) => array_filter(
            array_map($clean, array_intersect_key($item, array_flip($keys))),
            fn ($value) => $value !== '',
        );

        return $base + match ($format) {
            'reel' => ['scenes' => collect($data['scenes'] ?? [])
                ->filter(fn ($scene) => is_array($scene))
                ->map(fn ($scene) => $row($scene, ['time', 'voiceover', 'on_screen', 'shot']))
                ->filter(fn ($scene) => isset($scene['voiceover']))
                ->values()->all()],
            'story' => ['frames' => collect($data['frames'] ?? [])
                ->map(fn ($frame) => $row(is_array($frame) ? $frame : ['text' => $frame], ['text', 'visual', 'interaction', 'shot']))
                ->filter(fn ($frame) => isset($frame['text']))
                ->values()->all()],
            'thread' => ['tweets' => collect($data['tweets'] ?? [])
                ->map($clean)->filter()->values()->all()],
            'infographic' => [
                'title' => $clean($data['title'] ?? ''),
                'sections' => collect($data['sections'] ?? [])
                    ->filter(fn ($section) => is_array($section))
                    ->map(fn ($section) => ['heading' => $clean($section['heading'] ?? ''), 'text' => $clean($section['text'] ?? '')])
                    ->filter(fn ($section) => $section['heading'] !== '' || $section['text'] !== '')
                    ->values()->all(),
            ],
            default => [],
        };
    }

    /**
     * شريحة واحدة بشكل ثابت.
     *
     * إن اكتملت طبقات الهوك الثلاث صار النص مجموعها: ما يُرسم على الصورة هو
     * ما يُفحص ويُنشر، لا نسخة أخرى كتبها النموذج في حقل text.
     *
     * @return array{role: string, text: string, visual?: string, kicker?: string, focal?: string, tail?: string}
     */
    public static function slide(array $slide): array
    {
        $clean = fn ($key) => trim((string) ($slide[$key] ?? ''));

        $out = [
            'role' => in_array($slide['role'] ?? null, ['hook', 'promise', 'pull', 'harvest', 'ask'], true) ? $slide['role'] : 'pull',
            'text' => $clean('text'),
        ];

        if ($out['role'] === 'hook' && $clean('kicker') !== '' && $clean('focal') !== '') {
            $out['kicker'] = $clean('kicker');
            $out['focal'] = $clean('focal');
            $out['tail'] = $clean('tail');
            $out['text'] = trim(preg_replace('/\s+/u', ' ', "{$out['kicker']} {$out['focal']} {$out['tail']}"));
        }

        if ($clean('visual') !== '') {
            $out['visual'] = $clean('visual');
        }

        return $out;
    }

    /**
     * تحقق أدنى قبل الحفظ: مخرج فارغ يعني فشل مهمة لا محتوى بلا نص.
     */
    public static function isUsable(array $normalized, string $format): bool
    {
        return match ($format) {
            'carousel' => count($normalized['slides']) >= 3,
            // سكربت قديم نصاً واحداً، أو مشاهد
            'reel' => ! empty($normalized['scenes']) || filled($normalized['script']),
            'story' => ! empty($normalized['frames']),
            'thread' => ! empty($normalized['tweets']),
            'infographic' => count($normalized['sections'] ?? []) >= 2,
            default => filled($normalized['caption']),
        };
    }
}
