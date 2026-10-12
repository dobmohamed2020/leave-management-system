<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, LogsActivity;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'organization_id',
        'is_active',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'password'              => 'hashed',
            'is_active'             => 'boolean',
            'must_change_password'  => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Activity Log
    // -------------------------------------------------------------------------

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'organization_id', 'is_active', 'must_change_password'])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'remember_token'])
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => "تم إنشاء حساب المستخدم: {$this->name}",
                'updated' => "تم تحديث حساب المستخدم: {$this->name}",
                'deleted' => "تم حذف حساب المستخدم: {$this->name}",
                default   => $eventName,
            });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** The employee profile linked to this system user (if any) */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Normalize a phone number for storage and login lookup.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        // Arabic-Indic digits → Western digits
        $phone = strtr($phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        // Keep leading + and digits only
        $phone = preg_replace('/[^\d+]/', '', $phone) ?: '';

        return $phone !== '' ? $phone : null;
    }

    /**
     * Find an active-eligible user by email or phone for login.
     */
    public static function findForLogin(string $login): ?self
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return static::query()->where('email', $login)->first();
        }

        $phone = static::normalizePhone($login);

        if (! $phone) {
            return null;
        }

        return static::query()->where('phone', $phone)->first();
    }

    /**
     * Set the active team (organization) for permission scoping.
     * Must be called before checking team-scoped roles/permissions.
     */
    public function setOrganizationTeam(): void
    {
        if ($this->organization_id) {
            setPermissionsTeamId($this->organization_id);
        }
    }

    public function isAdmin(): bool
    {
        $this->setOrganizationTeam();
        return $this->hasRole(['مدير', 'مدير مساعد', 'مدير إدارة', 'مدير عام']);
    }

    public function canApproveLeave(): bool
    {
        $this->setOrganizationTeam();
        return $this->hasPermissionTo('approve_leave_request');
    }

    // -------------------------------------------------------------------------
    // Filament Panel Access
    // -------------------------------------------------------------------------

    /**
     * تحديد ما إذا كان المستخدم مسموحاً له بالدخول إلى لوحة الإدارة.
     * يجب استدعاء setOrganizationTeam() أولاً لضمان صحة فحص الأدوار في Spatie Teams.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $this->setOrganizationTeam();

        return $this->hasRole([
                'مدير المديرية',
                'وكيل المديرية',
                'مدير الإدارة',
                'مسؤول الإجازات',
                'شؤون عاملين',
                'كاتب الإدارة',
                'مدير مدرسة',
                'وكيل مدرسة',
                'أخصائي',
                'موظف مدرسة',
            ])
            || $this->hasPermissionTo('manage_organization');
    }
}
