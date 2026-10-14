<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApiOrganizationTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $user->setOrganizationTeam();
        }

        return $next($request);
    }
}
