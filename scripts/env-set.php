<?php

/**
 * تعديل مفاتيح في ملف .env دون المساس ببقية الملف.
 * الاستخدام: php scripts/env-set.php DB_USERNAME=root DB_PASSWORD=secret
 */

$envPath = dirname(__DIR__).'/.env';

if (! file_exists($envPath)) {
    fwrite(STDERR, "لا يوجد ملف .env\n");
    exit(1);
}

$contents = file_get_contents($envPath);
$changed = 0;

foreach (array_slice($argv, 1) as $pair) {
    if (! str_contains($pair, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $pair, 2);
    $key = trim($key);

    // القيم التي تحوي مسافات أو محارف خاصة تُغلَّف بعلامات اقتباس
    $quoted = preg_match('/[\s#"\']/', $value) ? '"'.str_replace('"', '\"', $value).'"' : $value;
    $line = $key.'='.$quoted;

    if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents)) {
        $contents = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents, 1);
    } else {
        $contents = rtrim($contents, "\r\n")."\n".$line."\n";
    }

    $changed++;
}

file_put_contents($envPath, $contents);
echo "حُدّث {$changed} مفتاح في .env\n";
