<?php

namespace App\Http\Controllers;

use App\Enums\ColorRole;
use App\Services\Brand\VisualIdentityUpdater;
use Illuminate\Http\Request;

class BrandIdentityController extends Controller
{
    public function __construct(protected VisualIdentityUpdater $identity) {}

    public function edit()
    {
        $brand = $this->brand();

        return view('brands.identity', [
            'brand' => $brand->load('logos'),
            'roles' => ColorRole::options(),
            'patternSlots' => $this->identity->patternSlotsLeft($brand),
        ]);
    }

    /** النموذج الرئيسي: ألوان وخطوط وتوجيه أسلوبي. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'colors' => ['nullable', 'array', 'max:'.config('brand.colors_max')],
            'colors.*.hex' => ['nullable', 'string', 'regex:/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'colors.*.name' => ['nullable', 'string', 'max:40'],
            'colors.*.role' => ['nullable', 'string', 'in:'.implode(',', array_keys(ColorRole::options()))],

            'fonts' => ['nullable', 'array'],
            'fonts.*' => ['nullable', 'string', 'max:60'],

            'visual_style' => ['nullable', 'string', 'max:255'],
            'design_summary' => ['nullable', 'string', 'max:2000'],
        ], [
            'colors.*.hex.regex' => 'كود اللون يجب أن يكون بصيغة HEX مثل ‎#6F4E37‎.',
        ], [
            'colors.*.hex' => 'كود اللون',
            'colors.*.name' => 'اسم اللون',
        ]);

        $this->identity->update($this->brand(), $data);

        return back()->with('status', 'حُفظت الهوية البصرية. ستُستخدم في كل تصميم قادم.');
    }

    /** الأنماط تُرفع فور اختيارها: لا معنى لانتظار حفظ نموذج كامل لصورة. */
    public function storePatterns(Request $request)
    {
        $brand = $this->brand();
        $slots = $this->identity->patternSlotsLeft($brand);

        $request->validate([
            'patterns' => ['required', 'array', 'max:'.config('brand.patterns_max')],
            'patterns.*' => ['image', 'max:4096'],
        ], [], ['patterns' => 'الأنماط', 'patterns.*' => 'صورة النمط']);

        // السقف يُفحص على المجموع لا على الرفعة: خمس صور محفوظة ورفعتان تتجاوزان الستة
        if (count($request->file('patterns')) > $slots) {
            return back()->withErrors([
                'patterns' => $slots === 0
                    ? 'بلغت الحد الأقصى للأنماط. احذف نمطاً قبل رفع غيره.'
                    : "المتبقي {$slots} من الأنماط فقط. ارفع عدداً أقل أو احذف نمطاً قديماً.",
            ]);
        }

        $this->identity->addPatterns($brand, $request->file('patterns'));

        return back()->with('status', 'أُضيفت الأنماط.');
    }

    public function destroyPattern(int $index)
    {
        $this->identity->removePattern($this->brand(), $index);

        return back()->with('status', 'حُذف النمط.');
    }
}
