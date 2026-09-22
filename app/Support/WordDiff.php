<?php

namespace App\Support;

/**
 * فرق نصين بالكلمات: كم تغيّر، وما الذي تغيّر بالضبط.
 *
 * يحكم به على المدقق اللغوي: تصحيح «أصلح» إلى «أصبح» كلمة واحدة،
 * أما إعادة صياغة الجملة فعشر كلمات — وهذه ليست مهمته.
 */
final class WordDiff
{
    /**
     * @return array{changed: int, total: int, changes: array<int, array{before: string, after: string}>}
     *               changed: كلمات النص الأطول التي لا تقابلها كلمة مطابقة في الآخر
     */
    public static function compare(string $before, string $after): array
    {
        $x = preg_split('/\s+/u', trim($before), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $y = preg_split('/\s+/u', trim($after), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($x);
        $m = count($y);

        // أطول تتابع مشترك، من النهاية لتسهل قراءة الفرق من البداية
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $x[$i] === $y[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $changes = [];
        $removed = [];
        $added = [];

        $flush = function () use (&$changes, &$removed, &$added) {
            if ($removed !== [] || $added !== []) {
                $changes[] = ['before' => implode(' ', $removed), 'after' => implode(' ', $added)];
                $removed = $added = [];
            }
        };

        $i = $j = 0;

        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $x[$i] === $y[$j]) {
                $flush();
                $i++;
                $j++;
            } elseif ($j < $m && ($i === $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $added[] = $y[$j++];
            } else {
                $removed[] = $x[$i++];
            }
        }

        $flush();

        return [
            'changed' => max($n, $m) - $lcs[0][0],
            'total' => max($n, $m),
            'changes' => $changes,
        ];
    }
}
