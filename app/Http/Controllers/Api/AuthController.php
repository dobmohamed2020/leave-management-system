<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::findForLogin($request->loginIdentifier());

        $throttleKey = 'api-login:'.sha1(strtolower($request->loginIdentifier()).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return $this->error("محاولات دخول كثيرة. أعد المحاولة بعد {$seconds} ثانية.", 429);
        }

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            Log::warning('auth.api.login_failed', [
                'ip' => $request->ip(),
                'login' => $request->loginIdentifier(),
                'user_id' => $user?->id,
            ]);

            app(AuditLogService::class)->recordAuth('فشل تسجيل الدخول عبر API', [
                'ip' => $request->ip(),
                'login' => $request->loginIdentifier(),
            ], $user);

            return $this->error('بيانات الدخول غير صحيحة.', 401);
        }

        RateLimiter::clear($throttleKey);

        if (! $user->is_active) {
            return $this->error('حسابك غير مفعّل. تواصل مع المدير.', 403);
        }

        $user->load('organization');

        // Revoke old tokens to keep only one active token per user
        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        app(AuditLogService::class)->recordAuth('تسجيل دخول ناجح عبر API', [
            'ip' => $request->ip(),
        ], $user);

        return $this->success([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'type' => $user->organization->type->value,
                    'type_label' => $user->organization->type->label(),
                ] : null,
            ],
        ], 'تم تسجيل الدخول بنجاح.');
    }

    public function logout(Request $request): JsonResponse
    {
        // Delete current token if available, otherwise delete all tokens
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        } else {
            $request->user()->tokens()->delete();
        }

        return $this->success(null, 'تم تسجيل الخروج بنجاح.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->fresh()->load('organization');
        $user->setOrganizationTeam();

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->getAttribute('phone'),
            'organization' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
                'type' => $user->organization->type->value,
                'type_label' => $user->organization->type->label(),
            ] : null,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'is_active' => $user->is_active,
        ]);
    }
}
