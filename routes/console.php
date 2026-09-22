<?php

use App\Models\Brand;
use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// منح صلاحية إدارة المنصة (إعدادات الذكاء الاصطناعي) — أو سحبها بـ --revoke
Artisan::command('platform:admin {email} {--revoke}', function (string $email) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error("لا يوجد مستخدم بالبريد {$email}");

        return 1;
    }

    $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
    $this->info($user->is_admin ? "{$email} أصبح مديراً للمنصة." : "سُحبت صلاحية الإدارة من {$email}.");
})->purpose('منح أو سحب صلاحية إدارة المنصة');

// تجديد حصة النقاط لكل براند انتهت دورته
Schedule::call(function (CreditService $credits) {
    Brand::whereNotNull('credits_reset_at')
        ->where('credits_reset_at', '<=', now())
        ->chunkById(100, function ($brands) use ($credits) {
            foreach ($brands as $brand) {
                $credits->grantMonthlyAllowance($brand);
            }
        });
})->hourly()->name('credits:renew')->withoutOverlapping();

// مهام التوليد العالقة (عامل طابور متوقف) تُنهى وتُرجع نقاطها بدل حجزها بلا نهاية
Schedule::command('ai:reap-stuck-jobs')->everyFiveMinutes()->withoutOverlapping();
