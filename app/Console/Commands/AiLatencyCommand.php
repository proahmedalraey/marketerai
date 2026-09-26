<?php

namespace App\Console\Commands;

use App\Models\AiUsageLog;
use App\Models\GenerationJob;
use Illuminate\Console\Command;

/**
 * أين ذهب وقت كل مهمة؟
 *
 * زمن المهمة الكلي يتوزع على: طلبات نموذج ناجحة، ومحاولات فاشلة (503 وانقطاع اتصال...)،
 * وانتظار بين المحاولات، وباقيها عملنا نحن (فحص الجودة وحفظ النتائج). كان الفشل
 * والانتظار مخفيين لأن الناجح وحده يُسجَّل؛ هذا الأمر يكشفهما بدل التخمين.
 */
class AiLatencyCommand extends Command
{
    protected $signature = 'ai:latency {--jobs=15 : عدد آخر المهام} {--type= : نوع المهمة (content, brand_profile, image...)}';

    protected $description = 'تفصيل وقت كل مهمة توليد: ناجح وفاشل وانتظار وباقي';

    public function handle(): int
    {
        $jobs = GenerationJob::withoutBrandScope()
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->whereIn('id', AiUsageLog::query()->select('generation_job_id')->whereNotNull('generation_job_id'))
            ->when($this->option('type'), fn ($q, $type) => $q->where('type', $type))
            ->latest('id')
            ->limit(max(1, (int) $this->option('jobs')))
            ->get();

        if ($jobs->isEmpty()) {
            $this->warn('لا مهام مكتملة لها سجل استهلاك بعد.');

            return self::SUCCESS;
        }

        $logs = AiUsageLog::whereIn('generation_job_id', $jobs->pluck('id'))->get()->groupBy('generation_job_id');
        $rows = [];
        $totals = ['wall' => 0, 'ok' => 0, 'failed' => 0, 'waited' => 0];

        foreach ($jobs->sortBy('id') as $job) {
            $set = $logs->get($job->id, collect());
            $ok = $set->where('succeeded', true)->sum('latency_ms') / 1000;
            $failedSet = $set->where('succeeded', false);
            $failed = $failedSet->sum('latency_ms') / 1000;
            $waited = $failedSet->sum('waited_ms') / 1000;
            $wall = max(0, $job->started_at->diffInSeconds($job->finished_at, true));
            $other = max(0, $wall - $ok - $failed - $waited);

            foreach (['wall' => $wall, 'ok' => $ok, 'failed' => $failed, 'waited' => $waited] as $key => $value) {
                $totals[$key] += $value;
            }

            $rows[] = [
                $job->id,
                $job->type,
                $this->seconds($wall),
                $this->seconds($ok),
                $failedSet->count() ? $this->seconds($failed).' ('.$failedSet->count().')' : '-',
                $waited > 0 ? $this->seconds($waited) : '-',
                $this->seconds($other),
                $failedSet->pluck('status_code')->map(fn ($code) => $code ?? 'net')->unique()->implode(','),
            ];
        }

        $this->table(['Job', 'Type', 'Wall', 'AI ok', 'Failed (n)', 'Waited', 'Other', 'Codes'], $rows);

        $lost = $totals['failed'] + $totals['waited'];
        $this->line(sprintf(
            'المجموع: %.1f ث، منها %.1f ث في طلبات ناجحة و%.1f ث ضاعت في محاولات فاشلة وانتظار (%d%%).',
            $totals['wall'],
            $totals['ok'],
            $lost,
            $totals['wall'] > 0 ? round($lost / $totals['wall'] * 100) : 0,
        ));
        $this->line('Other = ما عدا ذلك: فحص الجودة وحفظ النتائج وتشغيل المهمة. الفشل قبل هذا التسجيل لا أثر له في الأرقام.');

        return self::SUCCESS;
    }

    protected function seconds(float $value): string
    {
        return number_format($value, 1).'s';
    }
}
