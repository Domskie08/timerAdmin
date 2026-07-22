<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsClient
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user() && ! $request->user()->is_admin && $request->user()->client_account_id,
            403
        );

        return $next($request);
    }
}
