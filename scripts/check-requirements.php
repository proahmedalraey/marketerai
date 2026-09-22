<?php

/**
 * فحص المتطلبات قبل تشغيل composer، حتى تظهر رسالة دقيقة
 * بدل جدار أخطاء من composer يصعب قراءته.
 */

$errors = [];
$warnings = [];

// نسخة PHP
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    $errors[] = 'نسخة PHP الحالية '.PHP_VERSION.' — المطلوب 8.2 أو أحدث.';
}

// إضافات يعتمد عليها Laravel ولا يعمل بدونها
$required = [
    'ctype' => 'معالجة المحارف',
    'curl' => 'طلبات HTTP لمزودي الذكاء الاصطناعي',
    'dom' => 'معالجة XML',
    'fileinfo' => 'رفع الملفات والصور',
    'filter' => 'التحقق من المدخلات',
    'hash' => 'التشفير وكلمات المرور',
    'mbstring' => 'النصوص العربية',
    'openssl' => 'تشفير التوكنات والجلسات',
    'pcre' => 'التعابير النمطية',
    'pdo_mysql' => 'الاتصال بقاعدة البيانات',
    'session' => 'الجلسات',
    'tokenizer' => 'قوالب Blade',
    'xml' => 'معالجة XML',
    'zip' => 'تثبيت الحزم عبر composer',
];

foreach ($required as $ext => $why) {
    if (! extension_loaded($ext)) {
        $errors[] = "الإضافة [{$ext}] غير مفعّلة — لازمة لـ: {$why}";
    }
}

// مفيدة وليست ملزمة
if (! extension_loaded('gd')) {
    $warnings[] = 'الإضافة [gd] غير مفعّلة — الصور التجريبية من المزود الوهمي ستكون فارغة. فعّلها لتجربة أفضل.';
}

// pcntl و posix غير موجودتين على ويندوز إطلاقاً، وهذا طبيعي.
// لهذا أُزيل Horizon و Pail من المشروع؛ مكانهما خادم الإنتاج على لينكس.

if ($warnings) {
    echo "\n";
    foreach ($warnings as $w) {
        echo "  [تنبيه] {$w}\n";
    }
}

if (! $errors) {
    echo "      كل المتطلبات مكتملة\n";
    exit(0);
}

echo "\n";
echo "  ===============================================\n";
echo "   نواقص يجب إصلاحها قبل المتابعة\n";
echo "  ===============================================\n\n";

foreach ($errors as $e) {
    echo "  ✗ {$e}\n";
}

$ini = php_ini_loaded_file();

echo "\n  الإصلاح:\n";
echo '  افتح الملف: '.($ini ?: 'php.ini')."\n";
echo "  وأزل الفاصلة المنقوطة من بداية أسطر الإضافات الناقصة، مثل:\n\n";
echo "      ;extension=pdo_mysql   ←   extension=pdo_mysql\n";
echo "      ;extension=gd          ←   extension=gd\n";
echo "      ;extension=zip         ←   extension=zip\n\n";
echo "  ثم احفظ الملف وأعد تشغيل setup.bat\n\n";

exit(1);
