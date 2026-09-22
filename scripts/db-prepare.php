<?php

/**
 * يتحقق من الاتصال بـ MySQL وينشئ قاعدة البيانات إن لم تكن موجودة.
 * يستخدم PDO مباشرة، فلا يحتاج أداة mysql في مسار النظام.
 *
 * الاستخدام: php scripts/db-prepare.php <host> <port> <database> <user> [password]
 */

[$script, $host, $port, $database, $user] = array_pad(array_slice($argv, 0, 5), 5, null);
$password = $argv[5] ?? '';

if (! $host || ! $database || ! $user) {
    fwrite(STDERR, "الاستخدام: php scripts/db-prepare.php <host> <port> <database> <user> [password]\n");
    exit(2);
}

if (! extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "إضافة pdo_mysql غير مفعّلة في PHP.\n");
    fwrite(STDERR, "افتح php.ini وأزل الفاصلة المنقوطة من السطر: extension=pdo_mysql\n");
    exit(3);
}

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "تعذر الاتصال بخادم MySQL على {$host}:{$port}\n");
    fwrite(STDERR, 'السبب: '.$e->getMessage()."\n\n");
    fwrite(STDERR, "تأكد أن الخادم يعمل (Laragon أو XAMPP أو خدمة MySQL) وأن اسم المستخدم وكلمة المرور صحيحان.\n");
    exit(4);
}

$exists = (bool) $pdo->query(
    'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '.$pdo->quote($database)
)->fetchColumn();

if ($exists) {
    echo "قاعدة البيانات [{$database}] موجودة مسبقاً.\n";
    exit(0);
}

$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "أُنشئت قاعدة البيانات [{$database}].\n";
