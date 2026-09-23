<?php

namespace App\Http\Middleware;

use App\Support\CorporateModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corporate routes do not exist while the module is off.
 *
 * 404 rather than 403: a feature that has not shipped should look like it is
 * not there, not like something the caller lacks permission for.
 */
class EnsureCorporateModuleEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(CorporateModule::enabled(), 404);

        return $next($request);
    }
}
