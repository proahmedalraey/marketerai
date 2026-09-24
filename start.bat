@echo off
chcp 65001 >nul
cd /d "%~dp0"
title تشغيل Marketer Ai

rem حذف ملف Vite hot المعلّق — وجوده بلا خادم Vite يُفقد الصفحات تنسيقها
if exist "public\hot" del /q "public\hot"

if not exist "vendor" goto :not_ready
if not exist ".env" goto :not_ready

echo.
echo   تشغيل Marketer Ai...
echo.

start "Marketer Ai - الخادم" cmd /k "chcp 65001 >nul && php artisan serve"
rem queue:listen لا queue:work: يقرأ الكود والإعدادات من جديد مع كل مهمة.
rem queue:work يحمّل الكود مرة ويبقى عليه، فيعمل بالمزود الوهمي وبكود قديم
rem بعد أي تحديث حتى يُعاد تشغيله يدوياً. timeout 600: أطول مهمة (خمس نسخ مع تصحيحها).
start "Marketer Ai - الطابور" cmd /k "chcp 65001 >nul && php artisan queue:listen --queue=content,media,default --tries=2 --timeout=600"

echo   فُتحت نافذتان: الخادم والطابور.
echo   نافذة الطابور إلزامية - بدونها يبقى كل توليد في الانتظار.
echo.
echo   افتح المتصفح على:  http://localhost:8000
echo.
echo   الدخول:  demo@marketer.test  /  password
echo.
timeout /t 4 >nul
start http://localhost:8000
exit /b 0

:not_ready
echo.
echo   المشروع غير مجهّز بعد. شغّل أولاً:  setup.bat
echo.
pause
exit /b 1
