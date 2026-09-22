<?php

namespace App\Jobs;

use App\Models\GenerationJob;
use App\Services\Credits\CreditService;
use App\Services\Import\DTO\DiscoveredProduct;
use App\Services\Import\ProductEnricher;
use App\Services\Import\ProductImporter;
use App\Support\CurrentBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * حفظ المنتجات المختارة ثم إثراؤها.
 *
 * الحفظ والإثراء خطوتان منفصلتان عمداً: المنتج يُحفظ أولاً،
 * فإن فشل الإثراء بقي المنتج ببيانات المتجر وأُرجعت نقطته وحدها.
 */
class ImportProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $generationJobId) {}

    public function handle(ProductImporter $importer, ProductEnricher $enricher, CreditService $credits): void
    {
        $job = GenerationJob::withoutBrandScope()->with('brand')->find($this->generationJobId);

        if (! $job || $job->status->isFinished()) {
            return;
        }

        CurrentBrand::run($job->brand, function () use ($job, $importer, $enricher, $credits) {
            $job->markProcessing();

            $payload = $job->payload;
            $platform = (string) ($payload['platform'] ?? '');

            $imported = [];
            $enriched = 0;
            $failed = [];

            foreach (($payload['products'] ?? []) as $raw) {
                $discovered = DiscoveredProduct::fromArray((array) $raw);

                try {
                    $product = $importer->import($job->brand, $discovered, $platform);
                } catch (\Throwable $e) {
                    Log::warning('تعذّر استيراد منتج', ['url' => $discovered->url, 'error' => $e->getMessage()]);
                    $failed[] = $discovered->title ?: $discovered->url;
                    $job->increment('children_done');

                    continue;
                }

                $imported[] = $product->id;

                try {
                    $enricher->enrich($product, $job);
                    $enriched++;
                } catch (\Throwable $e) {
                    // المنتج محفوظ ببيانات المتجر؛ الإثراء وحده هو ما فشل
                    Log::warning('تعذّر إثراء منتج', ['product' => $product->id, 'error' => $e->getMessage()]);
                    $failed[] = $product->title;
                }

                $job->increment('children_done');
            }

            // نسوّي على المُثرى فقط: ما لم يُثرَ لم يستهلك نموذجاً
            $credits->settle($job->brand, (int) $job->credits_held, $enriched, $job, ProductEnricher::OPERATION);

            $job->markCompleted([
                'product_ids' => $imported,
                'imported' => count($imported),
                'enriched' => $enriched,
                'failed' => array_slice($failed, 0, 20),
            ], $failed === [] ? null : 'partial');
        });
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
            ProductEnricher::OPERATION,
            'إرجاع تلقائي بعد فشل الاستيراد'
        );

        $job->markFailed($e->getMessage());
    }
}
