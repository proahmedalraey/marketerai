<?php

namespace App\Jobs;

use App\Models\GenerationJob;
use App\Services\AI\ProviderException;
use App\Services\Brand\BrandProfileGenerator;
use App\Services\Credits\CreditService;
use App\Support\CurrentBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateBrandProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $generationJobId) {}

    public function handle(BrandProfileGenerator $generator): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        try {
            // العامل بلا جلسة: نضبط سياق البراند يدوياً ليعمل عزل المستأجر
            CurrentBrand::run($job->brand, fn () => $generator->run($job));
        } catch (ProviderException $e) {
            if ($e->retryable) {
                throw $e;
            }

            // حصة نفدت أو طلب مرفوض: إعادة الطابور لن تغيّر النتيجة وتستنزف حصة شحيحة.
            // ننهي المهمة الآن بنفس مسار الفشل (إرجاع النقاط ورسالة مفهومة).
            $this->failed($e);
        }
    }

    public function failed(\Throwable $e): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job) {
            return;
        }

        app(CreditService::class)->refund(
            $job->brand,
            (float) $job->credits_held,
            $job,
            BrandProfileGenerator::OPERATION,
            'إرجاع تلقائي بعد فشل المهمة'
        );

        // الرسالة تُعرض لصاحب المتجر؛ نص المزود الخام بقي في السجل
        $job->markFailed(ProviderException::messageFor($e));
    }
}
