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

class GenerateContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    // حتى خمس نسخ، ولكل نسخة طلب تصحيح محتمل: عشرة طلبات في أسوأ حال
    public int $timeout = 480;

    public function __construct(public int $generationJobId) {}

    public function handle(ContentGenerationService $service): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        // العامل بلا جلسة: نضبط سياق البراند يدوياً ليعمل عزل المستأجر
        CurrentBrand::run($job->brand, fn () => $service->run($job));
    }

    public function failed(\Throwable $e): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job) {
            return;
        }

        // إرجاع كامل الحجز: المستخدم لا يدفع مقابل فشل عندنا
        app(CreditService::class)->refund(
            $job->brand,
            (int) $job->credits_held,
            $job,
            'content',
            'إرجاع تلقائي بعد فشل المهمة'
        );

        // الرسالة تُعرض لصاحب المتجر؛ نص المزود الخام بقي في السجل
        $job->markFailed(\App\Services\AI\ProviderException::messageFor($e));
    }
}
