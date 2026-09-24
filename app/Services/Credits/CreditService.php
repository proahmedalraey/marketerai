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
 *
 * النقاط كسرية بخانة عشرية واحدة (مصفوفة دقة×جودة استوديو الصور تصل لـ0.5)،
 * لذا كل مبلغ هنا float مقرَّب لخانة واحدة — لا حاجة لـ bcmath عند هذه الدقة الثابتة.
 */
class CreditService
{
    /**
     * تكلفة عملية بالنقاط من ملف الإعداد.
     */
    public function cost(string $operation, int $quantity = 1): float
    {
        // مفاتيح التكلفة تحوي نقطة داخل الاسم نفسه، وصيغة config('a.b.c') تقرأ النقطة
        // كتداخل فتعيد null وتحسب كل عملية بنقطة واحدة. نجلب المصفوفة ثم نفهرس مباشرة.
        $unit = (float) (config('credits.costs', [])[$operation] ?? 1);

        return round(max($unit * max($quantity, 1), 0), 1);
    }

    public function balance(Brand $brand): float
    {
        return (float) $brand->fresh()->credit_balance;
    }

    /**
     * حجز نقاط قبل إطلاق المهمة.
     *
     * @throws InsufficientCreditsException
     * @throws DailyCapReachedException
     */
    public function hold(Brand $brand, string $operation, int $quantity = 1, ?GenerationJob $job = null): float
    {
        $amount = $this->cost($operation, $quantity);

        if ($amount === 0.0) {
            return 0.0;
        }

        $this->assertDailyCap($brand, $amount);

        return DB::transaction(function () use ($brand, $amount, $operation, $job) {
            // القفل يمنع سباق مهمتين متزامنتين على نفس الرصيد
            $locked = Brand::whereKey($brand->getKey())->lockForUpdate()->firstOrFail();

            if ((float) $locked->credit_balance < $amount) {
                throw new InsufficientCreditsException($amount, (float) $locked->credit_balance);
            }

            $locked->decrement('credit_balance', $amount);
            $balanceAfter = (float) $locked->fresh()->credit_balance;

            $this->record($locked, -$amount, $balanceAfter, 'hold', $operation, $job);

            if ($job) {
                $job->update(['credits_held' => (float) $job->credits_held + $amount]);
            }

            $brand->refresh();

            return $amount;
        });
    }

    /**
     * تسوية الحجز بعد نجاح المهمة كلياً أو جزئياً.
     * ما لم يُستهلك من المحجوز يعود للرصيد.
     */
    public function settle(Brand $brand, float $held, float $consumed, ?GenerationJob $job = null, ?string $operation = null): void
    {
        $consumed = max(min($consumed, $held), 0);
        $toRefund = round($held - $consumed, 1);

        DB::transaction(function () use ($brand, $held, $consumed, $toRefund, $job, $operation) {
            $locked = Brand::whereKey($brand->getKey())->lockForUpdate()->firstOrFail();

            if ($toRefund > 0) {
                $locked->increment('credit_balance', $toRefund);
                $balanceAfter = (float) $locked->fresh()->credit_balance;
                $this->record($locked, $toRefund, $balanceAfter, 'refund', $operation, $job,
                    "إرجاع ما لم يُستهلك من حجز {$held} نقطة");
            }

            if ($job) {
                $job->update([
                    'credits_charged' => $consumed,
                    'credits_held' => max((float) $job->credits_held - $held, 0),
                ]);
            }

            $this->record($locked, 0, (float) $locked->fresh()->credit_balance, 'settle', $operation, $job,
                "استُهلك {$consumed} من {$held}");

            $brand->refresh();
        });
    }

    /**
     * إرجاع كامل الحجز عند فشل المهمة.
     */
    public function refund(Brand $brand, float $amount, ?GenerationJob $job = null, ?string $operation = null, ?string $note = null): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($brand, $amount, $job, $operation, $note) {
            $locked = Brand::whereKey($brand->getKey())->lockForUpdate()->firstOrFail();
            $locked->increment('credit_balance', $amount);

            $this->record($locked, $amount, (float) $locked->fresh()->credit_balance, 'refund', $operation, $job,
                $note ?? 'إرجاع بعد فشل المهمة');

            if ($job) {
                $job->update(['credits_held' => max((float) $job->credits_held - $amount, 0)]);
            }

            $brand->refresh();
        });
    }

    /**
     * منح حصة الاشتراك الشهرية. يُستدعى من مهمة مجدولة أو بعد نجاح الدفع.
     */
    public function grantMonthlyAllowance(Brand $brand, ?float $amount = null): void
    {
        $amount ??= (float) ($brand->credits_allowance ?: config('credits.monthly_allowance'));

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
    public function usedToday(Brand $brand): float
    {
        $sum = CreditLedgerEntry::withoutBrandScope()
            ->where('brand_id', $brand->id)
            ->whereIn('reason', ['hold', 'refund'])
            ->whereDate('created_at', now()->toDateString())
            ->sum('delta');

        return round(abs(min((float) $sum, 0)), 1);
    }

    protected function assertDailyCap(Brand $brand, float $amount): void
    {
        $cap = config('credits.daily_cap');

        if ($cap === null) {
            return;
        }

        $used = $this->usedToday($brand);

        if ($used + $amount > (float) $cap) {
            throw new DailyCapReachedException((float) $cap, $used);
        }
    }

    protected function record(
        Brand $brand,
        float $delta,
        float $balanceAfter,
        string $reason,
        ?string $operation = null,
        ?GenerationJob $job = null,
        ?string $note = null
    ): void {
        CreditLedgerEntry::withoutBrandScope()->create([
            'brand_id' => $brand->id,
            'generation_job_id' => $job?->id,
            'delta' => round($delta, 1),
            'balance_after' => round($balanceAfter, 1),
            'reason' => $reason,
            'operation' => $operation,
            'note' => $note,
        ]);
    }
}
