<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class MarketingController extends Controller
{
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
}
