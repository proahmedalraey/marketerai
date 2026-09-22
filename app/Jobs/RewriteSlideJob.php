<?php

namespace App\Jobs;

use App\Models\GenerationJob;
use App\Services\Content\ContentGenerationService;
use App\Services\Credits\CreditService;
use App\Support\CurrentBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * إعادة كتابة شريحة واحدة: حتى ثلاثة طلبات (الشريحة، تصحيحها، تدقيقها).
 */
class RewriteSlideJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(public int $generationJobId) {}

    public function handle(ContentGenerationService $service): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        CurrentBrand::run($job->brand, fn () => $service->rewriteSlide($job));
    }

    public function failed(\Throwable $e): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job) {
            return;
        }

        app(CreditService::class)->refund(
            $job->brand,
            (int) $job->credits_held,
            $job,
            ContentGenerationService::SLIDE_OPERATION,
            'إرجاع تلقائي بعد فشل إعادة كتابة الشريحة'
        );

        $job->markFailed(\App\Services\AI\ProviderException::messageFor($e));
    }
}
