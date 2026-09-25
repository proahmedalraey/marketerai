<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * يبني مصغّرات الشبكة للصور التي وُلّدت أو رُفعت قبل وجودها.
 * الصور الجديدة تُنشأ لها المصغّرة عند الحفظ؛ هذا الأمر للقديمة فقط ويمكن إعادة تشغيله بأمان.
 */
class MediaThumbnailsCommand extends Command
{
    protected $signature = 'media:thumbnails';

    protected $description = 'Backfill grid thumbnails for existing image assets';

    public function handle(): int
    {
        $made = $skipped = 0;

        MediaAsset::withoutGlobalScopes()
            ->where('kind', 'image')
            ->chunkById(50, function ($assets) use (&$made, &$skipped) {
                foreach ($assets as $asset) {
                    if (! empty($asset->meta['thumb'])) {
                        continue;
                    }

                    $disk = Storage::disk($asset->disk);

                    if (! $disk->exists($asset->path)) {
                        $skipped++;

                        continue;
                    }

                    $thumb = MediaAsset::putThumbnail($asset->disk, $asset->path, $disk->get($asset->path));

                    if ($thumb) {
                        $asset->update(['meta' => ($asset->meta ?? []) + ['thumb' => $thumb]]);
                        $made++;
                    } else {
                        $skipped++;
                    }
                }
            });

        $this->info("أُنشئت {$made} مصغّرة، وتُخطّيت {$skipped} (ملف مفقود أو صغيرة أصلاً).");

        return self::SUCCESS;
    }
}
