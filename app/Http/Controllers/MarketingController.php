<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class MarketingController extends Controller
{
    /** السياسات المرتبطة من التذييل ومن موافقة التسجيل. */
    private const LEGAL_PAGES = [
        'privacy' => 'سياسة الخصوصية',
        'refund' => 'سياسة الاسترجاع والإلغاء',
    ];

    /** الزائر يرى الصفحة التعريفية، ومن سجّل دخوله يذهب مباشرة للوحة. */
    public function home(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        return view('marketing.home');
    }

    public function about(): View
    {
        return view('marketing.about');
    }

    public function legal(string $page): View
    {
        abort_unless(isset(self::LEGAL_PAGES[$page]), 404);

        return view('marketing.legal', ['title' => self::LEGAL_PAGES[$page]]);
    }
}
