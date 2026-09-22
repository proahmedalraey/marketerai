<?php

namespace App\Http\Middleware;

use App\Support\CurrentBrand;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يضبط البراند الحالي لكل طلب، ويوجّه لبناء الهوية إن لم تكتمل.
 */
class EnsureBrandIsReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $brand = $user->resolveBrand();

        if (! $brand) {
            // CurrentBrand ثابت على مستوى العملية: في عملية طويلة العمر (Octane، الاختبارات)
            // يبقى فيه براند الطلب السابق، فيحفظ مستخدمٌ جديد إجاباته فوق علامة غيره.
            CurrentBrand::clear();

            // صفحة الهوية هي التي تنشئ العلامة، فيجب أن تُفتح بلا علامة.
            // التوجيه إليها من داخلها كان يدور في حلقة لا تنتهي عند كل تسجيل جديد.
            return $request->routeIs('brand.profile', 'brand.profile.answers', 'brand.profile.prefill')
                ? $next($request)
                : redirect()->route('brand.profile');
        }

        CurrentBrand::set($brand);
        view()->share('currentBrand', $brand);

        if (! $brand->onboarding_completed && ! $request->routeIs('brand.*')) {
            return redirect()->route('brand.profile');
        }

        return $next($request);
    }
}
