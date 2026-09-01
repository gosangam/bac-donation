<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        // 404 rather than 403: an admin area should not confirm it exists to
        // someone who has no business there.
        abort_unless($request->user()?->is_admin, 404);

        return $next($request);
    }
}
