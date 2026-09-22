<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Testimonial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * حقائق البيع: كل ما يجوز للمحتوى أن يعد به العميل.
 *
 * التوصيل والدفع والعروض وتجارب العملاء هي بالضبط ما اخترعه نموذج المنافس
 * حين غابت (خطة التدقيق، المرحلة 2). هنا يُدخلها التاجر مرة، فتدخل دفتر
 * الحقائق في كل توليد، ويُرفض ما سواها.
 */
class StoreFactsController extends Controller
{
    public function edit()
    {
        $brand = $this->brand();

        return view('store.facts', [
            'brand' => $brand,
            'facts' => (array) $brand->store_facts,
            'offers' => Offer::with('product')->orderByDesc('is_active')->orderBy('ends_at')->latest('id')->get(),
            'testimonials' => Testimonial::with('product')->latest()->get(),
            'products' => Product::where('is_active', true)->orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'delivery' => ['nullable', 'string', 'max:300'],
            'free_shipping' => ['nullable', Rule::in(['always', 'over'])],
            'free_shipping_over' => ['nullable', 'required_if:free_shipping,over', 'numeric', 'min:1', 'max:1000000'],
            'branches' => ['nullable', 'string', 'max:300'],
            'channels' => ['nullable', 'array'],
            'channels.*' => [Rule::in(['retail', 'wholesale'])],
            'payments' => ['nullable', 'array'],
            'payments.*' => [Rule::in(array_keys(Brand::PAYMENT_METHODS))],
            'returns' => ['nullable', 'string', 'max:300'],
        ], [], [
            'delivery' => 'التوصيل والشحن',
            'free_shipping' => 'الشحن المجاني',
            'free_shipping_over' => 'الحد الأدنى للشحن المجاني',
            'branches' => 'الفروع',
            'payments' => 'طرق الدفع',
            'returns' => 'الاسترجاع والاستبدال',
        ]);

        $facts = array_filter([
            'delivery' => trim((string) ($data['delivery'] ?? '')),
            'free_shipping' => $data['free_shipping'] ?? null,
            'free_shipping_over' => ($data['free_shipping'] ?? null) === 'over' ? (float) $data['free_shipping_over'] : null,
            'branches' => trim((string) ($data['branches'] ?? '')),
            'channels' => array_values(array_unique($data['channels'] ?? [])),
            'payments' => array_values(array_unique($data['payments'] ?? [])),
            'returns' => trim((string) ($data['returns'] ?? '')),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        $this->brand()->update(['store_facts' => $facts ?: null]);

        return back()->with('status', 'حُفظت حقائق المتجر. تدخل كل توليد من الآن.');
    }

    // ================================================================
    //  العروض
    // ================================================================

    public function storeOffer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(array_keys(Offer::TYPES))],
            'value' => ['nullable', 'required_if:type,percent,amount', 'numeric', 'min:0.01', 'max:1000000'],
            'coupon_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'conditions' => ['nullable', 'string', 'max:300'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('brand_id', $this->brand()->id)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at', 'after_or_equal:today'],
        ], [
            'coupon_code.regex' => 'الكود حروف إنجليزية وأرقام فقط، بلا مسافات.',
            'value.required_if' => 'حدد قيمة الخصم.',
        ], [
            'title' => 'اسم العرض',
            'type' => 'نوع العرض',
            'value' => 'قيمة الخصم',
            'coupon_code' => 'الكود',
            'conditions' => 'الشروط',
            'product_id' => 'المنتج',
            'starts_at' => 'بداية العرض',
            'ends_at' => 'نهاية العرض',
        ]);

        if ($data['type'] === 'percent' && (float) ($data['value'] ?? 0) > 100) {
            return back()->withInput()->withErrors(['value' => 'نسبة الخصم لا تتجاوز 100%.']);
        }

        Offer::create([
            ...$data,
            'coupon_code' => filled($data['coupon_code'] ?? null) ? strtoupper($data['coupon_code']) : null,
            'value' => in_array($data['type'], ['percent', 'amount'], true) ? $data['value'] : null,
            'brand_id' => $this->brand()->id,
            'is_active' => true,
        ]);

        return back()->with('status', 'أُضيف العرض. يذكره المحتوى ما دام سارياً، ويتوقف عنه بعد انتهائه.');
    }

    public function toggleOffer(Offer $offer): RedirectResponse
    {
        $offer->update(['is_active' => ! $offer->is_active]);

        return back()->with('status', $offer->is_active ? 'فُعّل العرض.' : 'أُوقف العرض: لن يذكره أي محتوى جديد.');
    }

    public function destroyOffer(Offer $offer): RedirectResponse
    {
        $offer->delete();

        return back()->with('status', 'حُذف العرض.');
    }

    // ================================================================
    //  تجارب العملاء
    // ================================================================

    public function storeTestimonial(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:80'],
            'display_as' => ['required', Rule::in(array_keys(Testimonial::DISPLAY))],
            'body' => ['required', 'string', 'min:10', 'max:600'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'source' => ['nullable', 'string', 'max:60'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('brand_id', $this->brand()->id)],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'لا تُضاف تجربة عميل دون إذنه بنشرها.',
        ], [
            'author_name' => 'اسم العميل',
            'display_as' => 'طريقة عرض الاسم',
            'body' => 'نص التجربة',
            'rating' => 'التقييم',
            'source' => 'المصدر',
            'product_id' => 'المنتج',
        ]);

        Testimonial::create([
            ...collect($data)->except('consent')->all(),
            'body' => trim($data['body']),
            'brand_id' => $this->brand()->id,
            'consented_at' => now(),
        ]);

        return back()->with('status', 'أُضيفت التجربة. صار قالب «دليل اجتماعي» متاحاً، ويقتبسها بنصها.');
    }

    public function destroyTestimonial(Testimonial $testimonial): RedirectResponse
    {
        $testimonial->delete();

        return back()->with('status', 'حُذفت التجربة: لن تُقتبس في أي محتوى جديد.');
    }
}
