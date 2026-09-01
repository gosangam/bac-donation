<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Keeps admins out of the giving flow. An admin donating through the same
 * account they administer muddles the donor list, the transaction table and the
 * receipt trail — if a staff member wants to give, they use a donor account.
 */
class EnsureUserIsDonor
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->is_admin) {
            return redirect()->route('admin.index')
                ->with('status', 'Admin accounts cannot donate. Use a donor account to give.');
        }

        return $next($request);
    }
}
