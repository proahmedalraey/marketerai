<?php

namespace App\Services\Credits;

use App\Models\Brand;
use App\Models\CreditLedgerEntry;
use App\Models\GenerationJob;
use Illuminate\Support\Facades\DB;

/**
 * محاسبة النقاط.
 *
 * القاعدة: نحجز قبل التنفيذ ونسوّي بعده.
 * الخصم بعد النجاح فقط يسمح لمستخدم واحد بإطلاق مئة مهمة متزامنة برصيد خمس نقاط.
 *
 * كل حركة تُسجَّل في دفتر أستاذ لا يُحذف منه سطر؛ رصيد brands لقطة سريعة فقط.
 */
class CreditService
{
    /**
     * تكلفة عملية بالنقاط من ملف الإعداد.
     */
    public function cost(string $operation, int $quantity = 1): int
    {
        // مفاتيح التكلفة تحوي نقطة داخل الاسم نفسه، وصيغة config('a.b.c') تقرأ النقطة
        // كتداخل فتعيد null وتحسب كل عملية بنقطة واحدة. نجلب المصفوفة ثم نفهرس مباشرة.
        $unit = (int) (config('credits.costs', [])[$operation] ?? 1);

        return max($unit * max($quantity, 1), 0);
    }

    public function balance(Brand $brand): int
    {
        return (int) $brand->fresh()->credit_balance;
    }

    /**
     * حجز نقاط قبل إطلاق المهمة.
     *
     * @throws InsufficientCreditsException
     * @throws DailyCapReachedException
     */
    public function hold(Brand $brand, string $operation, int $quantity = 1, ?GenerationJob $job = null): int
    {
        $amount = $this->cost($operation, $quantity);

        if ($amount === 0) {
            return 0;
        }

        $this->assertDailyCap($brand, $amount);

        return DB::transaction(function () use ($brand, $amount, $operation, $job) {
            // القفل يمنع سباق مهمتين متزامنتين على نفس الرصيد
            $locked = Brand::whereKey($brand->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->credit_balance < $amount) {
                throw new InsufficientCreditsException($amount, (int) $locked->credit_balance);
            }

            $locked->decrement('credit_balance', $amount);
            $balanceAfter = (int) $locked->fresh()->credit_balance;

            $this->record($locked, -$amount, $balanceAfter, 'hold', $operation, $job);

            if ($job) {
                $job->update(['credits_held' => $job->credits_held + $amount]);
            }

            $brand->refresh();

            return $amount;
        });
    }

    /**
     * تسوية الحجز بعد نجاح المهمة كلياً أو جزئياً.
     * ما لم يُستهلك من المحجوز يعود للرصيد.
     */
    public function settle(Brand $brand, int $held, int $consumed, ?GenerationJob $job = null, ?string $operation = null): void
    {
        $consumed = max(min($consumed, $held), 0);
        $toRefund = $held - $consumed;

        DB::transaction(function () use ($brand, $held, $consumed, $toRefund, $job, $operation) {
            $locked = Brand::whereKey($brand->getKey())->lockForUpdate()->firstOrFail();

            if ($toRefund > 0) {
                $locked->increment('credit_balance', $toRefund);
                $balanceAfter = (int) $locked->fresh()->credit_balance;
                $this->record($locked, $toRefund, $balanceAfter, 'refund', $operation, $job,
                    "إرجاع ما لم يُستهلك من حجز {$held} نقطة");
            }

            if ($job) {
                $job->update([
                    'credits_charged' => $consumed,
                    'credits_held' => max($job->credits_held - $held, 0),
                ]);
            }

            $this->record($locked, 0, (int) $locked->fresh()->credit_balance, 'settle', $operation, $job,
                "استُهلك {$consumed} من {$held}");

            $brand->refresh();
        });
    }

    /**
     * إرجاع كامل الحجز عند فشل المهمة.
     */
    public function refund(Brand $brand, int $amount, ?GenerationJob $job = null, ?string $operation = null, ?string $note = null): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($brand, $amount, $job, $operation, $note) {
            $locked = Brand::whereKey($brand->getKey())->lockForUpdate()->firstOrFail();
            $locked->increment('credit_balance', $amount);

            $this->record($locked, $amount, (int) $locked->fresh()->credit_balance, 'refund', $operation, $job,
                $note ?? 'إرجاع بعد فشل المهمة');

            if ($job) {
                $job->update(['credits_held' => max($job->credits_held - $amount, 0)]);
            }

            $brand->refresh();
        });
    }

    /**
     * منح حصة الاشتراك الشهرية. يُستدعى من مهمة مجدولة أو بعد نجاح الدفع.
     */
    public function grantMonthlyAllowance(Brand $brand, ?int $amount = null): void
    {
        $amount ??= (int) ($brand->credits_allowance ?: config('credits.monthly_allowance'));

        DB::transaction(function () use ($brand, $amount) {
            $locked = Brand::whereKey($brand->getKey())->lockForUpdate()->firstOrFail();

            // الحصة لا تتراكم: تُستبدل ولا تُضاف
            $locked->update([
                'credit_balance' => $amount,
                'credits_reset_at' => now()->addMonth(),
            ]);

            $this->record($locked, $amount, $amount, 'grant', null, null, 'حصة الاشتراك الشهرية');

            $brand->refresh();
        });
    }

    /**
     * ما استُهلك فعلياً اليوم (الحجوزات ناقص الإرجاعات).
     */
    public function usedToday(Brand $brand): int
    {
        $sum = CreditLedgerEntry::withoutBrandScope()
            ->where('brand_id', $brand->id)
            ->whereIn('reason', ['hold', 'refund'])
            ->whereDate('created_at', now()->toDateString())
            ->sum('delta');

        return (int) abs(min((int) $sum, 0));
    }

    protected function assertDailyCap(Brand $brand, int $amount): void
    {
        $cap = config('credits.daily_cap');

        if ($cap === null) {
            return;
        }

        $used = $this->usedToday($brand);

        if ($used + $amount > (int) $cap) {
            throw new DailyCapReachedException((int) $cap, $used);
        }
    }

    protected function record(
        Brand $brand,
        int $delta,
        int $balanceAfter,
        string $reason,
        ?string $operation = null,
        ?GenerationJob $job = null,
        ?string $note = null
    ): void {
        CreditLedgerEntry::withoutBrandScope()->create([
            'brand_id' => $brand->id,
            'generation_job_id' => $job?->id,
            'delta' => $delta,
            'balance_after' => $balanceAfter,
            'reason' => $reason,
            'operation' => $operation,
            'note' => $note,
        ]);
    }
}
