<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class RegisteredUserController
{
    public function create()
    {
        return view('auth.register');
    }

    /**
     * الباقة والتجربة وكود الخصم تُعرض في النموذج فقط حتى يُبنى الدفع — لا تُقرأ هنا.
     * لا حقل تأكيد لكلمة المرور: زر الإظهار في النموذج يغني عنه.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'وافق على سياسة الخصوصية وسياسة الاسترجاع لإكمال التسجيل.',
        ]);

        $user = User::create(Arr::only($data, ['name', 'email', 'password']));

        Auth::login($user);

        return redirect()->route('brand.profile');
    }
}
