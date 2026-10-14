#!/usr/bin/env bash
# الإعداد الأول لـ Marketer Ai على Hostinger (veonasa.com) — مرة واحدة، من Git Bash:
#   bash deploy/hostinger-setup.sh
#
# يجهّز التطبيق في ~/domains/veonasa.com/app دون أن يمس الموقع الحالي (public_html):
# رفع الكود والأصول، composer، .env، كلمة مرور القاعدة، الهجرات، الكاش، وتجربة داخلية.
# كلمة المرور تُكتب مخفية وتذهب عبر SSH إلى .env مباشرة: لا تظهر في سطر أوامر ولا سجل.
# تحويل الدومين والـ cron خطوة منفصلة بعد التحقق. آمن لإعادة التشغيل.
set -Eeuo pipefail

HOST="u260325039@92.112.189.146"
PORT=65002
KEY="$HOME/.ssh/hostinger_marketer"
APP="domains/veonasa.com/app"   # نسبةً لمجلد المستخدم على الخادم
LOG="storage/logs/hostinger-setup.log"

cd "$(dirname "$0")/.."
exec > >(tee "$LOG") 2>&1
trap 'echo; echo "!! توقف بخطأ عند السطر $LINENO — السجل في $LOG"' ERR

# على ويندوز نستخدم ssh المدمج في النظام: برنامج الحماية حجب ssh الخاص بـ Git وسمح بهذا
SSH=ssh
if [ -x /c/Windows/System32/OpenSSH/ssh.exe ]; then
    SSH=/c/Windows/System32/OpenSSH/ssh.exe
    KEY="$(cygpath -w "$KEY")"
fi
remote() { MSYS_NO_PATHCONV=1 "$SSH" -i "$KEY" -p "$PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$HOST" "$@"; }
step() { echo; echo "== $*"; }

step "فحص الخادم"
remote bash -s <<'EOF'
set -e
php -r 'echo "PHP ", PHP_VERSION, " (", PHP_BINARY, ")", PHP_EOL; exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' \
    || { echo "PHP في سطر الأوامر أقدم من 8.2"; exit 1; }
command -v composer >/dev/null || { echo "composer غير موجود"; exit 1; }
composer -V 2>/dev/null | head -1
for e in pdo_mysql mbstring openssl fileinfo curl gd; do php -m | grep -qix "$e" || echo "إضافة ناقصة: $e"; done
echo "disable_functions: $(php -r 'echo ini_get("disable_functions");')"
echo "crontab: $(crontab -l 2>&1 | head -3)"
echo "db: $(mysql --version 2>&1)"
ls -la ~/domains/veonasa.com
if [ -e ~/domains/veonasa.com/app ] && [ ! -f ~/domains/veonasa.com/app/artisan ]; then
    echo "~/domains/veonasa.com/app موجود وليس تطبيقنا — توقف"; exit 1
fi
EOF

step "بناء الأصول ورفع الكود ($(git rev-parse --short HEAD))"
npm run build
git -c core.autocrlf=false archive --format=tar HEAD -- \
    app bootstrap config database lang public resources routes artisan composer.json composer.lock deploy/env.production \
    | remote "mkdir -p $APP && cd $APP && tar -xf -"
tar -cf - public/build | remote "cd $APP && rm -rf public/build && tar -xf -"

step "composer و.env"
remote bash -s <<EOF
set -e
cd $APP
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
[ -f .env ] || cp deploy/env.production .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
[ -L public/storage ] || ln -s ../storage/app/public public/storage
EOF

# مساعد كتابة كلمة المرور: يقرؤها من stdin فلا تمر في سطر الأوامر، ويُحذف بعد الاستخدام
remote "cat > ~/.marketer-set-db-password.php" <<'PHP'
<?php
$p = rtrim((string) fgets(STDIN), "\r\n");
if ($p === '') { fwrite(STDERR, "كلمة مرور فارغة\n"); exit(1); }
if (! str_contains($p, "'")) {
    $v = "'".$p."'";                                  // المفردتان حرفيتان تمامًا في phpdotenv
} elseif (! str_contains($p, '$')) {
    $v = '"'.addcslashes($p, '"\\').'"';
} else {
    fwrite(STDERR, "كلمة المرور فيها ' و\$ معًا — غيّرها من hPanel بلا أحدهما\n"); exit(1);
}
$env = file_get_contents('.env');
$env = preg_replace_callback('/^DB_PASSWORD=.*$/m', fn () => 'DB_PASSWORD='.$v, $env, 1, $n);
if ($n !== 1) { fwrite(STDERR, "سطر DB_PASSWORD غير موجود في .env\n"); exit(1); }
file_put_contents('.env', $env);
echo "كُتبت كلمة المرور في .env\n";
PHP

step "الاتصال بقاعدة البيانات u260325039_marketer"
db_ok() { remote "cd $APP && php artisan db:show >/dev/null 2>&1"; }
tries=0
until db_ok; do
    tries=$((tries + 1))
    [ "$tries" -le 3 ] || { echo "تعذّر الاتصال بعد 3 محاولات"; remote "cd $APP && php artisan db:show 2>&1 | grep -m3 -E 'SQLSTATE|Exception|denied'" || true; exit 1; }
    [ "$tries" -gt 1 ] && echo "لم ينجح الاتصال — أعد الكتابة."
    read -rsp "اكتب كلمة مرور قاعدة البيانات (لن تظهر) ثم Enter: " DBPASS; echo
    printf '%s\n' "$DBPASS" | remote "cd $APP && php ~/.marketer-set-db-password.php"
    unset DBPASS
done
remote "rm -f ~/.marketer-set-db-password.php"
echo "الاتصال سليم."

step "الهجرات والكاش"
remote bash -s <<EOF
set -e
cd $APP
php artisan migrate --force
php artisan optimize
php artisan about --only=environment,cache,drivers || true
EOF

step "تجربة داخل الخادم قبل تحويل الدومين"
remote bash -s <<EOF
cd $APP
php -S 127.0.0.1:8765 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >/dev/null 2>&1 &
pid=\$!
sleep 3
for u in /up / /login /register; do echo "\$u -> \$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8765\$u)"; done
kill \$pid 2>/dev/null || true
EOF

step "انتهى الإعداد. الموقع الحالي لم يُمس؛ تحويل الدومين خطوة تالية."
