<?php

namespace App\Jobs;

use App\Models\GenerationJob;
use App\Services\AI\ProviderException;
use App\Services\Credits\CreditService;
use App\Services\Media\PromptEnhancer;
use App\Support\CurrentBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * تحسين وصف صورة: طلب نصي واحد أو اثنان (الثاني إن رصد الحارس اختلاقاً).
 * محاولة واحدة للطابور: من ينتظر بجانب الزر لا يحتمل إعادة كاملة، والمحاولات
 * العابرة يتولاها AiManager داخل الطلب.
 */
class EnhancePromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $generationJobId) {}

    public function handle(PromptEnhancer $enhancer): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        try {
            CurrentBrand::run($job->brand, fn () => $enhancer->run($job));
        } catch (ProviderException $e) {
            // محاولة واحدة للطابور: الفشل نهائي فنُرجع النقاط ونعرض السبب هنا (لا إعادة تستنزف)
            $this->failed($e);
        }
    }

    public function failed(\Throwable $e): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        app(CreditService::class)->refund(
            $job->brand,
            (float) $job->credits_held,
            $job,
            PromptEnhancer::OPERATION,
            'إرجاع تلقائي بعد فشل تحسين الوصف'
        );

        $job->markFailed(ProviderException::messageFor($e));
    }
}
