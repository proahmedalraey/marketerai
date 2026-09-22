<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\GenerationJob;
use App\Services\Credits\CreditService;
use Illuminate\Console\Command;

/**
 * يُسقط مهام التوليد العالقة ويُرجع نقاطها.
 *
 * إن توقف عامل الطابور تبقى المهمة «في الانتظار» ونقاطها محجوزة بلا نهاية،
 * والمستخدم لا يرى إلا دوّاراً. بعد المهلة نُنهيها بصدق ونُرجع ما حُجز.
 *
 * آمن مع عودة العامل لاحقاً: كل مهمة تتحقق من isFinished() قبل أن تبدأ،
 * فلا تُنفَّذ مهمة أُسقطت ولا تُخصم نقاطها مرتين.
 */
class ReapStuckJobsCommand extends Command
{
    protected $signature = 'ai:reap-stuck-jobs {--minutes= : المهلة بالدقائق (الافتراضي من config/ai.php)}';

    protected $description = 'إنهاء مهام التوليد العالقة وإرجاع نقاطها';

    public const MESSAGE = 'توقفت المهمة دون أن تكتمل. أُرجعت نقاطك — جرّب مجدداً.';

    public function handle(CreditService $credits): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('ai.stuck_after_minutes', 15));
        $cutoff = now()->subMinutes($minutes);
        $reaped = 0;

        // المهام الأم فقط: النقاط محجوزة عليها، والأبناء يُنهَون معها بلا إرجاع مكرر
        GenerationJob::withoutBrandScope()
            ->with('brand')
            ->whereNull('parent_id')
            ->whereIn('status', [JobStatus::Queued, JobStatus::Processing])
            ->where('updated_at', '<', $cutoff)
            ->chunkById(100, function ($jobs) use ($credits, &$reaped) {
                foreach ($jobs as $job) {
                    if ($job->brand && $job->credits_held > 0) {
                        $credits->refund($job->brand, (int) $job->credits_held, $job, $job->type, 'إرجاع: مهمة عالقة');
                    }

                    $job->markFailed(self::MESSAGE);

                    GenerationJob::withoutBrandScope()
                        ->where('parent_id', $job->id)
                        ->whereIn('status', [JobStatus::Queued, JobStatus::Processing])
                        ->get()
                        ->each->markFailed(self::MESSAGE);

                    $reaped++;
                }
            });

        $this->info($reaped === 0 ? 'لا مهام عالقة.' : "أُنهيت {$reaped} مهمة عالقة وأُرجعت نقاطها.");

        return self::SUCCESS;
    }
}
