<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (session('achievement_auth')) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $password = (string) config('services.dashboard.password');

        if ($password !== '' && hash_equals($password, $data['password'])) {
            $request->session()->regenerate();
            $request->session()->put('achievement_auth', true);

            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors(['password' => 'That password did not unlock the vault.'])->onlyInput();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('achievement_auth');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
