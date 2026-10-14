<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Employee extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope());

        static::saved(function (Employee $employee) {
            if ($employee->promotion_date && ($employee->grade || $employee->entitlement_grade)) {
                $exists = $employee->promotions()
                    ->where('promotion_date', $employee->promotion_date->format('Y-m-d'))
                    ->exists();

                if (! $exists) {
                    $employee->promotions()->create([
                        'grade'             => $employee->grade ?? $employee->entitlementGrade?->name ?? 'غير محددة',
                        'entitlement_grade' => $employee->entitlement_grade,
                        'promotion_date'    => $employee->promotion_date,
                        'decision_number'   => $employee->promotion_decision_number,
                        'notes'             => 'تم الحفظ من ملف الموظف',
                        'created_by'        => auth()->id(),
                    ]);
                }
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'user_id',
        'employee_code',
        'full_name',
        'job_title',
        'approval_role',
        'grade',
        'entitlement_grade',
        'promotion_date',
        'promotion_decision_number',
        'birth_date',
        'hire_date',
        'work_start_date',
        'phone',
        'is_active',
    ];

    /**
     * Strip accidental Arabic diacritics / zero-width chars from employee codes
     * (e.g. Damma U+064F typed before Latin letters on Arabic keyboards).
     */
    public function setEmployeeCodeAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['employee_code'] = null;

            return;
        }

        $clean = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
        $this->attributes['employee_code'] = trim((string) $clean);
    }

    protected $casts = [
        'birth_date'      => 'date',
        'hire_date'       => 'date',
        'work_start_date' => 'date',
        'promotion_date'  => 'date',
        'is_active'       => 'boolean',
        'approval_role'   => \App\Enums\ApprovalRole::class,
        // entitlement_grade is a string code referencing entitlement_grades.code
    ];

    // -------------------------------------------------------------------------
    // Activity Log
    // -------------------------------------------------------------------------

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "تم تسجيل الموظف: {$this->full_name}",
                'updated' => "تم تحديث بيانات الموظف: {$this->full_name}",
                'deleted' => "تم حذف الموظف: {$this->full_name}",
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

    /** System user linked to this employee (optional) */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** الدرجة الوظيفية (من جدول entitlement_grades) */
    public function entitlementGrade(): BelongsTo
    {
        return $this->belongsTo(EntitlementGrade::class, 'entitlement_grade', 'code');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /** سجل الترقيات والتدرج الوظيفي */
    public function promotions(): HasMany
    {
        return $this->hasMany(EmployeePromotion::class)->orderByDesc('promotion_date');
    }

    /** آخر ترقية مسجلة للموظف */
    public function lastPromotion(): ?EmployeePromotion
    {
        return $this->promotions()->first();
    }

    /**
     * تسجيل ترقية جديدة للموظف وتحديث بيانات الدرجة الحالية تلقائياً.
     */
    public function recordPromotion(
        string $grade,
        ?string $entitlementGrade = null,
        $promotionDate = null,
        ?string $decisionNumber = null,
        ?string $notes = null,
        ?int $userId = null
    ): EmployeePromotion {
        $promoDate = $promotionDate ? \Carbon\Carbon::parse($promotionDate) : now();

        $promo = $this->promotions()->create([
            'grade'             => $grade,
            'entitlement_grade' => $entitlementGrade ?? $this->entitlement_grade,
            'promotion_date'    => $promoDate,
            'decision_number'   => $decisionNumber,
            'notes'             => $notes,
            'created_by'        => $userId ?? auth()->id(),
        ]);

        $this->update([
            'grade'                     => $grade,
            'entitlement_grade'         => $entitlementGrade ?? $this->entitlement_grade,
            'promotion_date'            => $promoDate,
            'promotion_decision_number' => $decisionNumber,
        ]);

        return $promo;
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
     * Get the leave balance for a specific type and year.
     * Returns null if no balance record exists yet.
     */
    public function balanceFor(int $leaveTypeId, ?int $year = null): ?LeaveBalance
    {
        return $this->leaveBalances()
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year ?? now()->year)
            ->first();
    }

    /** Check if employee has any approved leave on a given date */
    public function isOnLeaveOn(\Carbon\Carbon $date): bool
    {
        return $this->leaveRequests()
            ->where('status', \App\Enums\LeaveStatus::APPROVED->value)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();
    }

    /**
     * Calculate yearly entitlement for regular leave based on entitlement_grade and age.
     * Falls back to 28 days if grade is not set.
     * When a grade IS assigned and the employee is over 50, the age rule overrides the grade.
     */
    public function regularLeaveEntitlement(): int
    {
        if ($this->entitlement_grade === null) {
            return 28;
        }

        if ($this->birth_date && $this->birth_date->age >= 50) {
            return (int) (EntitlementGrade::findByCode('admin_over50')?->yearly_days ?? 50);
        }

        if ($this->relationLoaded('entitlementGrade') && $this->entitlementGrade) {
            return (int) $this->entitlementGrade->yearly_days;
        }

        return (int) (EntitlementGrade::findByCode($this->entitlement_grade)?->yearly_days ?? 28);
    }

    /**
     * حساب مدة الخدمة / سنوات الخبرة معبراً عنها بـ (سنة، شهر، يوم).
     */
    public function getServiceDurationLabelAttribute(): string
    {
        $startDate = $this->work_start_date ?? $this->hire_date;
        if (! $startDate) {
            return '—';
        }

        $start = \Carbon\Carbon::parse($startDate);
        if ($start->isFuture()) {
            return '0 سنة و 0 شهر و 0 يوم';
        }

        $diff = $start->diff(now());

        return "{$diff->y} سنة و {$diff->m} شهر و {$diff->d} يوم";
    }
}

