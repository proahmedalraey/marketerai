<?php

namespace App\Services\Import;

use App\Enums\JobStatus;
use App\Jobs\ImportProductsJob;
use App\Jobs\ScanStoreJob;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\DB;

/**
 * منسّق الاستيراد.
 *
 * قاعدتان تحكمان التسعير هنا:
 * الاكتشاف مجاني لأن المستخدم لم يحصل على شيء بعد، وقد لا يستورد أصلاً.
 * والخصم يقع عند الحفظ بنقطة لكل منتج، وتُرجَع نقطة كل منتج يفشل إثراؤه.
 */
class ImportService
{
    public function __construct(protected CreditService $credits) {}

    /** مسح متجر: مهمة بلا حجز نقاط. */
    public function scan(Brand $brand, string $storeUrl, ?int $userId = null): GenerationJob
    {
        $job = GenerationJob::create([
            'brand_id' => $brand->id,
            'user_id' => $userId,
            'type' => 'store_scan',
            'status' => JobStatus::Queued,
            'payload' => ['store_url' => $storeUrl],
        ]);

        ScanStoreJob::dispatch($job->id)->onQueue('default');

        return $job->fresh();
    }

    /** قراءة دفعة إضافية من الروابط المكتشفة — مجانية أيضاً. */
    public function readMore(GenerationJob $scan, int $limit): GenerationJob
    {
        $job = GenerationJob::create([
            'brand_id' => $scan->brand_id,
            'user_id' => $scan->user_id,
            'type' => 'store_scan',
            'status' => JobStatus::Queued,
            'payload' => ['continues' => $scan->uuid, 'limit' => $limit],
        ]);

        ScanStoreJob::dispatch($job->id)->onQueue('default');

        return $job->fresh();
    }

    /**
     * استيراد المحدد. نحجز نقطة لكل منتج مقدماً،
     * ونسوّي الحساب في نهاية المهمة على عدد ما نجح فعلاً.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    public function import(Brand $brand, array $products, string $platform, ?int $userId = null): GenerationJob
    {
        $count = count($products);

        return DB::transaction(function () use ($brand, $products, $platform, $userId, $count) {
            $job = GenerationJob::create([
                'brand_id' => $brand->id,
                'user_id' => $userId,
                'type' => 'product_import',
                'status' => JobStatus::Queued,
                'payload' => ['platform' => $platform, 'products' => array_values($products)],
                'children_total' => $count,
            ]);

            $this->credits->hold($brand, ProductEnricher::OPERATION, $count, $job);

            ImportProductsJob::dispatch($job->id)->onQueue('content');

            return $job->fresh();
        });
    }

    public function costPerProduct(): int
    {
        return $this->credits->cost(ProductEnricher::OPERATION);
    }
}
