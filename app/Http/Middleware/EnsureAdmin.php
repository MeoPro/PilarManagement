<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user?->is_admin
                && $user->is_active
                && in_array($user->role, [User::ROLE_OWNER, User::ROLE_ADMIN], true),
            403,
        );

        return $next($request);
    }
}
