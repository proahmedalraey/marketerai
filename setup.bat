@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"
title تجهيز مشروع Marketer Ai

echo.
echo ===============================================
echo    تجهيز مشروع Marketer Ai
echo ===============================================
echo.

echo [1/9] التحقق من المتطلبات...

where php >nul 2>&1
if errorlevel 1 goto :no_php

where composer >nul 2>&1
if errorlevel 1 goto :no_composer

where npm >nul 2>&1
if errorlevel 1 goto :no_npm

php -r "echo PHP_VERSION;" > "%TEMP%\mai_phpver.txt" 2>nul
set /p PHPVER=<"%TEMP%\mai_phpver.txt"
del "%TEMP%\mai_phpver.txt" >nul 2>&1
echo       PHP %PHPVER% - جاهز
echo       Composer - جاهز
echo       npm - جاهز

call php scripts\check-requirements.php
if errorlevel 1 goto :missing_ext
echo.

echo [2/9] تثبيت حزم PHP... قد يستغرق دقيقتين
call composer install --no-interaction
if errorlevel 1 goto :composer_failed
echo.

echo [3/9] تجهيز ملف البيئة...
if exist ".env" goto :env_exists
copy ".env.example" ".env" >nul
echo       أُنشئ ملف .env
goto :env_done
:env_exists
echo       ملف .env موجود مسبقاً - لن يُستبدل
:env_done
echo.

echo [4/9] توليد مفتاح التطبيق...
call php artisan key:generate --force
echo.

echo [5/9] إعدادات قاعدة البيانات MySQL
echo       اتركها فارغة لاستخدام القيمة الافتراضية
echo.
set "DB_HOST=127.0.0.1"
set "DB_PORT=3306"
set "DB_NAME=marketer_ai"
set "DB_USER=root"
set "DB_PASS="

set /p "DB_USER=      اسم المستخدم [root]: "
if "%DB_USER%"=="" set "DB_USER=root"

set /p "DB_PASS=      كلمة المرور [فارغة]: "

set /p "DB_PORT=      المنفذ [3306]: "
if "%DB_PORT%"=="" set "DB_PORT=3306"
echo.

echo [6/9] الاتصال بـ MySQL وإنشاء قاعدة البيانات...
call php scripts\db-prepare.php "%DB_HOST%" "%DB_PORT%" "%DB_NAME%" "%DB_USER%" "%DB_PASS%"
if errorlevel 1 goto :db_failed

call php scripts\env-set.php "DB_CONNECTION=mysql" "DB_HOST=%DB_HOST%" "DB_PORT=%DB_PORT%" "DB_DATABASE=%DB_NAME%" "DB_USERNAME=%DB_USER%" "DB_PASSWORD=%DB_PASS%" "QUEUE_CONNECTION=database"
echo.

echo [7/9] إنشاء الجداول والبيانات التجريبية...
call php artisan migrate --seed --force
if errorlevel 1 goto :migrate_failed
echo.

echo [8/9] ربط مجلد الملفات...
call php artisan storage:link
echo.

echo [9/9] بناء الواجهة...
call npm install
if errorlevel 1 goto :npm_failed
call npm run build
if errorlevel 1 goto :npm_failed
echo.

echo ===============================================
echo    اكتمل التجهيز
echo ===============================================
echo.
echo   شغّل المشروع الآن بالنقر على:  start.bat
echo.
echo   الدخول:
echo     البريد: demo@marketer.test
echo     المرور: password
echo.
echo   المنصة تعمل بمزود ذكاء اصطناعي وهمي بلا مفاتيح ولا تكلفة.
echo   لتشغيل نموذج حقيقي عدّل AI_TEXT_PROVIDER في ملف .env
echo.
pause
exit /b 0

:no_php
echo.
echo   [خطأ] PHP غير موجود في مسار النظام.
echo   ثبّت PHP 8.2 أو أحدث، أو استخدم Laragon، وتأكد أن php في PATH.
echo.
pause
exit /b 1

:no_composer
echo.
echo   [خطأ] Composer غير موجود.
echo   نزّله من getcomposer.org ثم أعد تشغيل هذا الملف.
echo.
pause
exit /b 1

:no_npm
echo.
echo   [خطأ] Node.js غير موجود.
echo   نزّله من nodejs.org - النسخة LTS - ثم أعد تشغيل هذا الملف.
echo.
pause
exit /b 1

:composer_failed
echo.
echo   [خطأ] فشل تثبيت حزم PHP.
echo.
echo   إن كانت الرسالة تذكر ext-pcntl أو ext-posix فهذه إضافات لينكس
echo   ولا توجد على ويندوز إطلاقاً. أبلغني بالرسالة كاملة لأزيل الحزمة المسببة.
echo.
echo   وإن كانت مشكلة شبكة، جرّب:  composer install -vvv
echo.
pause
exit /b 1

:missing_ext
echo.
echo   أصلح النواقص أعلاه ثم شغّل setup.bat مرة أخرى.
echo.
pause
exit /b 1

:db_failed
echo.
echo   [خطأ] تعذر تجهيز قاعدة البيانات. راجع الرسالة أعلاه.
echo.
pause
exit /b 1

:migrate_failed
echo.
echo   [خطأ] فشل إنشاء الجداول. راجع الرسالة أعلاه.
echo.
pause
exit /b 1

:npm_failed
echo.
echo   [خطأ] فشل بناء الواجهة. جرّب: npm install ثم npm run build
echo.
pause
exit /b 1
