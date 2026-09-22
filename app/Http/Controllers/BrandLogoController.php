<?php

namespace App\Http\Controllers;

use App\Models\BrandLogo;
use Illuminate\Http\Request;

/**
 * المكتبة لا الشعار: الإفراد والمرآة إلى brands.logo_path
 * يتكفّل بهما BrandLogoObserver، فلا يعرف عنهما المتحكم شيئاً.
 */
class BrandLogoController extends Controller
{
    public function store(Request $request)
    {
        $brand = $this->brand();

        $data = $request->validate([
            'logo' => ['required', 'image', 'max:2048'],
            'label' => ['nullable', 'string', 'max:60'],
        ], [], ['logo' => 'ملف الشعار', 'label' => 'اسم الشعار']);

        if ($brand->logos()->count() >= (int) config('brand.logos_max')) {
            return back()->withErrors([
                'logo' => 'بلغت الحد الأقصى للشعارات ('.config('brand.logos_max').'). احذف شعاراً قبل إضافة آخر.',
            ]);
        }

        BrandLogo::create([
            'brand_id' => $brand->id,
            'disk' => config('ai.media_disk', 'public'),
            'path' => $request->file('logo')->store("brands/{$brand->id}/logos", config('ai.media_disk', 'public')),
            'label' => $data['label'] ?? null,
            'sort' => (int) $brand->logos()->max('sort') + 1,
        ]);

        return back()->with('status', 'أُضيف الشعار إلى المكتبة.');
    }

    public function update(Request $request, BrandLogo $logo)
    {
        abort_unless($logo->brand_id === $this->brand()->id, 404);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'is_default' => ['nullable', 'boolean'],
        ], [], ['label' => 'اسم الشعار']);

        $logo->fill(['label' => $data['label'] ?? $logo->label]);

        // لا نكتب false هنا: إلغاء التفضيل يتم بتفضيل غيره، وإلا بقيت العلامة بلا شعار
        if ($request->boolean('is_default')) {
            $logo->is_default = true;
        }

        $logo->save();

        return back()->with('status', 'حُدّث الشعار.');
    }

    public function destroy(BrandLogo $logo)
    {
        abort_unless($logo->brand_id === $this->brand()->id, 404);

        $logo->delete();

        return back()->with('status', 'حُذف الشعار.');
    }
}
