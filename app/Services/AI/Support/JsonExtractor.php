<?php

namespace App\Services\AI\Support;

/**
 * النماذج تخالف التعليمات أحياناً وتغلف JSON بسياج كود أو تسبقه بجملة.
 * هذا المستخرج يتعامل مع الحالات الشائعة بدل أن تفشل المهمة كاملة.
 */
class JsonExtractor
{
    public static function extract(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // 1) محاولة مباشرة
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // 2) إزالة سياج الكود ```json ... ```
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // 3) اقتطاع أول كائن متوازن الأقواس
        $start = strpos($raw, '{');
        if ($start !== false) {
            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($i = $start, $len = strlen($raw); $i < $len; $i++) {
                $char = $raw[$i];

                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = ! $inString;
                    continue;
                }

                if ($inString) {
                    continue;
                }

                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $candidate = substr($raw, $start, $i - $start + 1);
                        $decoded = json_decode($candidate, true);

                        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
                    }
                }
            }
        }

        return null;
    }
}
