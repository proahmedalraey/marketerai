<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use Illuminate\Console\Command;

/**
 * يحذف التعليقات الصوتية التي تجاوزت مدة الاحتفاظ ولم يحفظها التاجر.
 * الواجهة تعرض لكل ملف ما بقي له («29 يوم و 23 ساعة»)، وهذا ما ينفّذ الوعد.
 */
class VoiceoverPruneCommand extends Command
{
    protected $signature = 'voiceover:prune';

    protected $description = 'حذف التعليقات الصوتية المنتهية غير المحفوظة';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('voiceover.retention_days', 30));
        $deleted = 0;

        MediaAsset::withoutBrandScope()
            ->where('kind', 'audio')
            ->where('is_pinned', false)
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function ($assets) use (&$deleted) {
                foreach ($assets as $asset) {
                    $asset->deleteFiles();
                    $asset->delete();
                    $deleted++;
                }
            });

        $this->info("حُذف {$deleted} تعليق صوتي منتهٍ.");

        return self::SUCCESS;
    }
}
