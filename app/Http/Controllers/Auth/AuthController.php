<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $r)
    {
        $credentials = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($credentials, $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'ÕJÔ^Çéˆó¤ò´_ÕJ¤·¤Æ¤¯¤À¤µ¤¤'])->onlyInput('email');
        } $r->session()->regenerate();

        return redirect()->intended(route('assets.index'));
    }

    public function destroy(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
