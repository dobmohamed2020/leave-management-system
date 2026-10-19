<?php

use App\Exceptions\LeaveRequestException;
use App\Http\Middleware\EnsureApiUserOrganization;
use App\Http\Middleware\LogUserActivity;
use App\Http\Middleware\NormalizeArabicNumbers;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetApiOrganizationTeam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->append(NormalizeArabicNumbers::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->appendToGroup('api', SetApiOrganizationTeam::class);
        $middleware->appendToGroup('api', EnsureApiUserOrganization::class);
        $middleware->appendToGroup('web', LogUserActivity::class);
        $middleware->appendToGroup('api', LogUserActivity::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'بيانات غير صحيحة',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => 'غير مصرح بالوصول'], 401);
            }
        });
        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => 'السجل غير موجود'], 404);
            }
        });
        $exceptions->render(function (LeaveRequestException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        });
        $exceptions->render(function (AuthorizationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'غير مصرح لك بتنفيذ هذا الإجراء.',
                ], 403);
            }
        });
    })->create();
