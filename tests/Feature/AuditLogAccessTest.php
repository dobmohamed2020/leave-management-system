<?php

use App\Filament\Resources\AuditLogResource;
use App\Models\User;
use App\Support\SystemAudit;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

it('allows only the configured audit viewer email', function () {
    $admin = User::factory()->create(['email' => 'admin@rtltec.com']);
    $other = User::factory()->create(['email' => 'teacher@school.edu']);

    expect(SystemAudit::canViewAuditLog($admin))->toBeTrue()
        ->and(SystemAudit::canViewAuditLog($other))->toBeFalse();
});

it('denies Filament audit log resource for non-admin users', function () {
    $user = User::factory()->create(['email' => 'user@school.edu']);

    $this->actingAs($user);

    expect(AuditLogResource::canViewAny())->toBeFalse()
        ->and(AuditLogResource::shouldRegisterNavigation())->toBeFalse();
});

it('allows Filament audit log resource for admin@rtltec.com', function () {
    $admin = User::factory()->create(['email' => 'admin@rtltec.com']);

    $this->actingAs($admin);

    expect(AuditLogResource::canViewAny())->toBeTrue()
        ->and(AuditLogResource::shouldRegisterNavigation())->toBeTrue();
});

it('records audit log on successful API login', function () {
    $user = User::factory()->create([
        'email' => 'api-audit@rtltec.com',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);

    $this->postJson('/api/auth/login', [
        'email' => 'api-audit@rtltec.com',
        'password' => 'password123',
    ])->assertStatus(200);

    expect(
        Activity::query()
            ->where('log_name', 'audit')
            ->where('description', 'تسجيل دخول ناجح عبر API')
            ->where('causer_id', $user->id)
            ->exists()
    )->toBeTrue();
});
