<?php

namespace App\Jobs;

use App\Models\GenerationJob;
use App\Services\AI\ProviderException;
use App\Services\Credits\CreditService;
use App\Services\Voiceover\VoiceoverService;
use App\Support\CurrentBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * تسجيل تعليق صوتي. محاولة واحدة للطابور: إعادة كاملة تسجّل وتدفع مرتين،
 * والأخطاء العابرة يعيدها AiManager داخل الطلب نفسه.
 */
class GenerateVoiceoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $generationJobId) {}

    public function handle(VoiceoverService $service): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        try {
            CurrentBrand::run($job->brand, fn () => $service->run($job));
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

        app(CreditService::class)->refund(
            $job->brand,
            (float) $job->credits_held,
            $job,
            $job->payload['credit_operation'] ?? 'voice.minute',
            'إرجاع تلقائي بعد فشل التعليق الصوتي'
        );

        $job->markFailed(ProviderException::messageFor($e));
    }
}
