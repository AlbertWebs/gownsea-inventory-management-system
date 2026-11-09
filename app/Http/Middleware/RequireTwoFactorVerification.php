<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactorVerification
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->two_factor_confirmed_at === null || $request->routeIs('two-factor.challenge', 'two-factor.verify', 'logout')) {
            return $next($request);
        }

        $verifiedUser = (int) $request->session()->get('two_factor_verified_user', 0);
        if ($verifiedUser === $user->id) {
            return $next($request);
        }

        return redirect()->route('two-factor.challenge');
    }
}
