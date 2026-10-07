<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        abort_unless($user && collect($roles)->contains(fn ($r) => $user->hasRole($r)), 403);

        return $next($request);
    }
}
