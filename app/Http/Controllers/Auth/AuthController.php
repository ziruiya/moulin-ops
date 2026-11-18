<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function create()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function store(Request $request)
    {
        $request->mergeIfMissing(['login' => $request->input('username', $request->input('email'))]);
        $data = $request->validate(['login' => 'required|string|max:255', 'password' => 'required|string']);
        $field = str_contains($data['login'], '@') ? 'email' : 'username';
        if (! Auth::attempt([$field => $data['login'], 'password' => $data['password']], $request->boolean('remember'))) {
            return back()->withErrors(['login' => 'ユーザー名またはパスワードを確認してください。'])->onlyInput('login');
        }
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
