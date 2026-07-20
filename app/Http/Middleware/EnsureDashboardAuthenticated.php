<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('achievement_auth')) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
