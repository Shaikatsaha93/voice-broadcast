<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Deactivated users lose access immediately, even with a live session. */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->user()->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors(['email' => 'Your account is deactivated.']);
        }

        return $next($request);
    }
}
