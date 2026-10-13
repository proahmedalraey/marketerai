#!/usr/bin/env bash
# نشر تحديث على Hostinger (veonasa.com) من Git Bash على جهاز التطوير:
#   bash deploy/hostinger.sh
#
# يُنشر آخر commit فقط (لا التعديلات غير المحفوظة) + public/build مبنيًا محليًا: لا Node على الاستضافة.
# يفترض أن الإعداد الأول تم (.env وقاعدة البيانات والـ cron) — انظر قسم النشر في README.md.
set -euo pipefail

HOST="u260325039@92.112.189.146"
PORT=65002
KEY="$HOME/.ssh/hostinger_marketer"
APP="domains/veonasa.com/app"   # نسبةً لمجلد المستخدم على الخادم

# ما يحتاجه التشغيل فقط؛ tests وdocs وscripts لا تُرفع
RUNTIME=(app bootstrap config database lang public resources routes artisan composer.json composer.lock)
# مجلدات متتبَّعة بالكامل: تُحذف قبل الفك كي لا يبقى ملف حُذف من المستودع (هجرة قديمة مثلًا)
REPLACED="app config database lang resources routes"

remote() { ssh -i "$KEY" -p "$PORT" -o BatchMode=yes "$HOST" "$@"; }

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain -- "${RUNTIME[@]}")" ]; then
    echo "تنبيه: تعديلات غير محفوظة لن تُنشر — يُنشر $(git rev-parse --short HEAD) فقط."
fi

npm run build

# وضع الصيانة قبل استبدال الكود: الزائر يرى 503 بدل أخطاء ملفات ناقصة، والعمّال لا يلتقطون مهام جديدة
remote "cd $APP && php artisan down --retry=30"

git archive --format=tar HEAD -- "${RUNTIME[@]}" \
    | remote "cd $APP && rm -rf $REPLACED && tar -xf -"
tar -cf - public/build | remote "cd $APP && rm -rf public/build && tar -xf -"

remote bash -s <<EOF
set -e
cd $APP
# كاش الإعداد والمسارات القديم قد يشير لما حُذف، فيُسقط artisan قبل أن يعيد بناءه
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
php artisan migrate --force
[ -L public/storage ] || php artisan storage:link
php artisan optimize
php artisan queue:restart
php artisan up
EOF

echo "نُشر $(git rev-parse --short HEAD) على https://veonasa.com"
echo "إن توقف السكربت في منتصفه يبقى الموقع في وضع الصيانة: أصلح السبب ثم أعد تشغيله (أو php artisan up)."
