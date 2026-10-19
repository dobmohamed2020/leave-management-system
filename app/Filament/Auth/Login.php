<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Services\AuditLogService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('البريد الإلكتروني أو رقم التليفون')
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        $user = User::findForLogin((string) $data['email']);

        if (
            ! $user
            || ! Hash::check((string) $data['password'], $user->password)
        ) {
            $this->throwFailureValidationException();
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'data.email' => 'حسابك غير مفعّل. تواصل مع المدير.',
            ]);
        }

        if (
            ($user instanceof FilamentUser)
            && (! $user->canAccessPanel(Filament::getCurrentPanel()))
        ) {
            $this->throwFailureValidationException();
        }

        Filament::auth()->login($user, (bool) ($data['remember'] ?? false));

        session()->regenerate();

        app(AuditLogService::class)->recordAuth('تسجيل دخول إلى لوحة الإدارة', [
            'ip' => request()->ip(),
        ], $user);

        return app(LoginResponse::class);
    }
}
