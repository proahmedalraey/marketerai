<?php

namespace App\Jobs;

use App\Models\GenerationJob;
use App\Services\Credits\CreditService;
use App\Services\Media\ImageGenerationService;
use App\Support\CurrentBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $generationJobId) {}

    public function handle(ImageGenerationService $service): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        try {
            CurrentBrand::run($job->brand, fn () => $service->run($job));
        } catch (\App\Services\AI\ProviderException $e) {
            if ($e->retryable) {
                throw $e;
            }

            // حصة نفدت أو طلب مرفوض: إعادة الطابور لن تغيّر النتيجة وتستنزف حصة شحيحة
            $this->failed($e);
        }
    }

    public function failed(\Throwable $e): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job) {
            return;
        }

        app(CreditService::class)->refund($job->brand, (int) $job->credits_held, $job, 'image');
        // الرسالة تُعرض لصاحب المتجر؛ نص المزود الخام بقي في السجل
        $job->markFailed(\App\Services\AI\ProviderException::messageFor($e));

        // شريحة من كاروسيل: فشلها يُعدّ في المهمة الأم، وإلا بقيت معلّقة
        app(ImageGenerationService::class)->finishChild($job);
    }
}
