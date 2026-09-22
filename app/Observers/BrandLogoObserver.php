<?php

namespace App\Observers;

use App\Models\Brand;
use App\Models\BrandLogo;
use Illuminate\Support\Facades\Storage;

/**
 * يحرس ثلاث ثوابت لا يجوز كسرها مهما كان المسار الذي كتب الشعار:
 *   1. مفضّل واحد لكل علامة، لا صفر ولا اثنان.
 *   2. brands.logo_path مرآة للمفضّل — يقرؤه كود قائم لا يعرف بالمكتبة.
 *   3. حذف السجل يحذف الملف: المخزّن بلا سجل لا يصل إليه أحد.
 *
 * التحديثات هنا تمر بمنشئ الاستعلام لا بالنماذج، فلا تُطلق أحداثاً
 * تعيد استدعاء هذا المراقب.
 */
class BrandLogoObserver
{
    public function created(BrandLogo $logo): void
    {
        // أول شعار يصبح المفضّل بلا سؤال: مكتبة بلا مفضّل تعني تصاميم بلا شعار
        if (! $logo->is_default && ! $this->hasOtherDefault($logo)) {
            BrandLogo::withoutBrandScope()->whereKey($logo->id)->update(['is_default' => true]);
            $logo->setAttribute('is_default', true);
        }

        $this->settle($logo);
    }

    public function updated(BrandLogo $logo): void
    {
        $this->settle($logo);
    }

    public function deleted(BrandLogo $logo): void
    {
        Storage::disk($logo->disk)->delete($logo->path);

        $this->promoteIfNeeded($logo);
        $this->mirrorToBrand($logo);
    }

    /** يُفرد المفضّل ثم ينسخه إلى العلامة. */
    protected function settle(BrandLogo $logo): void
    {
        if ($logo->is_default) {
            BrandLogo::withoutBrandScope()
                ->where('brand_id', $logo->brand_id)
                ->whereKeyNot($logo->id)
                ->update(['is_default' => false]);
        } else {
            $this->promoteIfNeeded($logo);
        }

        $this->mirrorToBrand($logo);
    }

    /** إن لم يبقَ مفضّل، يرقّي الأول ترتيباً. */
    protected function promoteIfNeeded(BrandLogo $logo): void
    {
        if ($this->hasOtherDefault($logo)) {
            return;
        }

        $next = BrandLogo::withoutBrandScope()
            ->where('brand_id', $logo->brand_id)
            ->whereKeyNot($logo->id)
            ->orderBy('sort')
            ->orderBy('id')
            ->first();

        if ($next) {
            BrandLogo::withoutBrandScope()->whereKey($next->id)->update(['is_default' => true]);
        }
    }

    protected function hasOtherDefault(BrandLogo $logo): bool
    {
        return BrandLogo::withoutBrandScope()
            ->where('brand_id', $logo->brand_id)
            ->where('is_default', true)
            ->whereKeyNot($logo->id)
            ->exists();
    }

    protected function mirrorToBrand(BrandLogo $logo): void
    {
        $default = BrandLogo::withoutBrandScope()
            ->where('brand_id', $logo->brand_id)
            ->where('is_default', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->first();

        Brand::whereKey($logo->brand_id)->update(['logo_path' => $default?->path]);
    }
}
