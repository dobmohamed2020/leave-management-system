<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    public function __construct(
        private readonly AuditLogService $auditLog,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user()) {
            $channel = $request->is('api/*') ? 'api' : 'web';
            $this->auditLog->recordHttpRequest($request, $response->getStatusCode(), $channel);
        }

        return $response;
    }
}
