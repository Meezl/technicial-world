<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     * Supports multiple roles: role:admin,project_manager
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user()) {
            abort(403, 'Unauthorized');
        }

        if (!in_array($request->user()->role, $roles)) {
            abort(403, 'Unauthorized - insufficient role permissions');
        }

        return $next($request);
    }
}
