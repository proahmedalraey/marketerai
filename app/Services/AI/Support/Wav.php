<?php

namespace App\Services\AI\Support;

use InvalidArgumentException;

/**
 * ملفات WAV بلا مكتبات: نماذج النطق تعيد WAV جاهزاً أحياناً (gemini-3.8-flash-tts)
 * وPCM خاماً أحياناً (audio/L16 في نماذج 2.5)، والمتصفح لا يشغّل الخام.
 * لا ffmpeg على الخادم، فالحفظ WAV كما هو.
 */
class Wav
{
    /** يعيد ملف WAV قابلاً للتشغيل مهما كان ما أعاده المزود. */
    public static function normalize(string $bytes, ?string $mime = null): string
    {
        if (static::isWav($bytes)) {
            return $bytes;
        }

        $mime = strtolower((string) $mime);

        if ($mime === '' || str_contains($mime, 'l16') || str_contains($mime, 'pcm')) {
            $rate = preg_match('/rate=(\d+)/', $mime, $m) ? (int) $m[1] : 24000;

            return static::fromPcm($bytes, $rate);
        }

        throw new InvalidArgumentException("صيغة صوت غير مدعومة: {$mime}");
    }

    public static function isWav(string $bytes): bool
    {
        return strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE';
    }

    /** PCM خام (16 بت، صغير النهاية) ← WAV بترويسة 44 بايت. */
    public static function fromPcm(string $pcm, int $rate = 24000, int $channels = 1, int $bits = 16): string
    {
        $blockAlign = $channels * intdiv($bits, 8);
        $size = strlen($pcm);

        return pack('A4VA4', 'RIFF', 36 + $size, 'WAVE')
            .pack('A4VvvVVvv', 'fmt ', 16, 1, $channels, $rate, $rate * $blockAlign, $blockAlign, $bits)
            .pack('A4V', 'data', $size)
            .$pcm;
    }

    /** مدة الملف بالثواني من ترويسته: حجم كتلة البيانات ÷ البايتات في الثانية. */
    public static function duration(string $wav): float
    {
        if (! static::isWav($wav)) {
            return 0.0;
        }

        $byteRate = 0;
        $offset = 12;
        $length = strlen($wav);

        while ($offset + 8 <= $length) {
            $id = substr($wav, $offset, 4);
            $size = unpack('V', substr($wav, $offset + 4, 4))[1];
            $body = $offset + 8;

            if ($id === 'fmt ' && $body + 12 <= $length) {
                $byteRate = unpack('V', substr($wav, $body + 8, 4))[1];
            }

            if ($id === 'data') {
                // البث يكتب الحجم 0xFFFFFFFF أو 0 أحياناً: ما وصل فعلاً هو المرجع
                $available = $length - $body;
                $size = ($size === 0 || $size > $available) ? $available : $size;

                return $byteRate > 0 ? round($size / $byteRate, 2) : 0.0;
            }

            // الكتل ذات الحجم الفردي مُبطَّنة ببايت
            $offset = $body + $size + ($size % 2);
        }

        return 0.0;
    }
}
