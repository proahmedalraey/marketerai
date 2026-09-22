<?php

namespace App\Jobs;

use App\Models\GenerationJob;
use App\Services\Import\DTO\DiscoveryResult;
use App\Services\Import\StoreCrawler;
use App\Support\CurrentBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * مسح متجر: اكتشاف الروابط ثم قراءة أول دفعة.
 * لا نقاط هنا — الاكتشاف مجاني، والخصم يقع عند الحفظ.
 */
class ScanStoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $generationJobId) {}

    public function handle(StoreCrawler $crawler): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        CurrentBrand::run($job->brand, function () use ($job, $crawler) {
            $job->markProcessing();

            try {
                $result = $this->resolve($job, $crawler);
            } catch (\Throwable $e) {
                $job->markFailed($e->getMessage());

                return;
            }

            if ($result->items === [] && $result->pendingUrls === []) {
                $job->markFailed('لم نعثر على منتجات في هذا الرابط. تأكد أنه رابط المتجر لا صفحة داخلية.');

                return;
            }

            $job->markCompleted($result->toArray());
        });
    }

    protected function resolve(GenerationJob $job, StoreCrawler $crawler): DiscoveryResult
    {
        $payload = $job->payload;

        // متابعة مسح سابق: نكمل من حيث توقف بدل إعادة الاكتشاف من الصفر
        if ($previousUuid = ($payload['continues'] ?? null)) {
            $previous = GenerationJob::withoutBrandScope()
                ->where('brand_id', $job->brand_id)
                ->where('uuid', $previousUuid)
                ->firstOrFail();

            $result = DiscoveryResult::fromArray($previous->result ?? []);

            return $crawler->readBatch($result, (int) ($payload['limit'] ?? StoreCrawler::READ_BATCH));
        }

        $result = $crawler->discover((string) ($payload['store_url'] ?? ''));

        // منصات JSON تعطي البيانات كاملة فوراً؛ غيرها يحتاج قراءة أول دفعة
        return $result->items === []
            ? $crawler->readBatch($result)
            : $result;
    }

    public function failed(\Throwable $e): void
    {
        GenerationJob::withoutBrandScope()
            ->find($this->generationJobId)
            ?->markFailed($e->getMessage());
    }
}
