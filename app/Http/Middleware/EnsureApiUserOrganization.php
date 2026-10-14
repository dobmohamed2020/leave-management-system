<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API users without an organization must have manage_organization (e.g. system operators).
 */
class EnsureApiUserOrganization
{
    /** @var list<string> */
    private const EXEMPT_PATHS = [
        'api/auth/login',
        'api/auth/logout',
        'api/auth/me',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->organization_id === null) {
            $path = trim($request->path(), '/');

            if (! in_array($path, self::EXEMPT_PATHS, true) && ! $user->hasPermissionSafe('manage_organization')) {
                return response()->json([
                    'success' => false,
                    'message' => 'يجب ربط حسابك بمؤسسة لاستخدام واجهة API، أو امتلاك صلاحية إدارة المؤسسة.',
                ], 403);
            }
        }

        return $next($request);
    }
}
