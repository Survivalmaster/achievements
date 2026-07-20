<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SteamAchievementClient;
use App\Services\SteamOpenId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class AuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function redirectToSteam(Request $request, SteamOpenId $steam): RedirectResponse
    {
        $request->session()->put('url.intended', url()->previous() ?: route('dashboard'));

        return redirect()->away($steam->redirectUrl(route('steam.callback'), config('app.url')));
    }

    public function handleSteamCallback(Request $request, SteamOpenId $steam, SteamAchievementClient $client): RedirectResponse
    {
        try {
            $steamId = $steam->validate($request->query());
            $profile = $client->playerSummary($steamId);
        } catch (Throwable $exception) {
            return redirect()->route('login')->withErrors([
                'steam' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Steam login failed.',
            ]);
        }

        $user = User::updateOrCreate(
            ['steam_id' => $steamId],
            [
                'name' => $profile['personaname'] ?? "Steam {$steamId}",
                'email' => "{$steamId}@steam.local",
                'avatar' => $profile['avatarfull'] ?? $profile['avatarmedium'] ?? null,
                'profile_url' => $profile['profileurl'] ?? null,
                'password' => Str::password(48),
            ],
        );

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
