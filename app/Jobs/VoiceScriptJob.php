<?php

namespace App\Jobs;

use App\Models\GenerationJob;
use App\Services\AI\ProviderException;
use App\Services\Credits\CreditService;
use App\Services\Voiceover\VoiceScriptTools;
use App\Support\CurrentBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** أدوات نص التعليق (تحسين/تشكيل/توجيه): من ينتظر بجانب الزر لا يحتمل إعادة كاملة. */
class VoiceScriptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $generationJobId) {}

    public function handle(VoiceScriptTools $tools): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        try {
            CurrentBrand::run($job->brand, fn () => $tools->run($job));
        } catch (ProviderException $e) {
            $this->failed($e);
        }
    }

    public function failed(\Throwable $e): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        app(CreditService::class)->refund($job->brand, (float) $job->credits_held, $job, VoiceScriptTools::OPERATION, 'إرجاع تلقائي بعد فشل تجهيز النص');

        $job->markFailed(ProviderException::messageFor($e));
    }
}
