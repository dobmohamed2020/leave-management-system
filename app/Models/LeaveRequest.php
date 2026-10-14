<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveRequest extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope());
    }

    protected $fillable = [
        'number',
        'employee_id',
        'organization_id',
        'leave_type_id',
        'deduct_from_carried',
        'substitute_employee_id',
        'start_date',
        'end_date',
        'days',
        'written_at',
        'reason',
        'balance_entitled',
        'balance_used',
        'balance_remaining',
        'status',
        'was_modified',
        'current_stage',
        'rejection_reason',
        'created_by',
        'submitted_at',
        'decided_at',
        'decided_by',
    ];

    protected $casts = [
        'deduct_from_carried' => 'boolean',
        'start_date'        => 'date',
        'end_date'          => 'date',
        'written_at'        => 'date',
        'days'              => 'integer',
        'balance_entitled'  => 'integer',
        'balance_used'      => 'integer',
        'balance_remaining' => 'integer',
        'status'            => LeaveStatus::class,
        'was_modified'      => 'boolean',
        'submitted_at'      => 'datetime',
        'decided_at'        => 'datetime',
    ];

    /**
     * تسمية الحالة للواجهة: قائم / تم تعديله وقائم / تم حذفه …
     */
    public function displayStatusLabel(): string
    {
        if ($this->trashed() || $this->status === LeaveStatus::CANCELLED) {
            return 'تم حذفه';
        }

        if ($this->status === LeaveStatus::REJECTED) {
            return 'مرفوض';
        }

        if ($this->status === LeaveStatus::DRAFT) {
            return $this->was_modified ? 'تم تعديله وقائم' : 'مسودة';
        }

        if ($this->status === LeaveStatus::RETURNED) {
            return $this->was_modified ? 'تم تعديله وقائم' : 'مُعاد للتعديل';
        }

        // مقدّم / قيد المراجعة / معتمد
        return $this->was_modified ? 'تم تعديله وقائم' : 'قائم';
    }

    public function displayStatusColor(): string
    {
        if ($this->trashed() || $this->status === LeaveStatus::CANCELLED) {
            return 'danger';
        }

        if ($this->status === LeaveStatus::REJECTED) {
            return 'danger';
        }

        if ($this->was_modified) {
            return 'warning';
        }

        return $this->status->color();
    }

    // -------------------------------------------------------------------------
    // Activity Log
    // -------------------------------------------------------------------------

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(function (string $eventName) {
                $employee = "#{$this->employee_id}";
                if ($this->relationLoaded('employee')) {
                    $employee = $this->employee?->full_name ?? $employee;
                } elseif ($this->employee_id) {
                    $employee = Employee::query()->whereKey($this->employee_id)->value('full_name') ?? $employee;
                }

                return match ($eventName) {
                    'created' => "تم إنشاء طلب إجازة للموظف {$employee} - رقم {$this->number}",
                    'updated' => "تم تحديث طلب الإجازة رقم {$this->number} - الحالة: {$this->status->label()}",
                    'deleted' => "تم حذف طلب الإجازة رقم {$this->number}",
                    default   => $eventName,
                };
            });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function employee(): BelongsTo
    {
        // Keep history visible even if the employee was soft-deleted later.
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withoutGlobalScopes();
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function substituteEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'substitute_employee_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LeaveRequestStep::class)->orderBy('step_order');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveRequestAttachment::class);
    }

    public function balanceTransactions(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class);
    }

    // -------------------------------------------------------------------------
    // Labels
    // -------------------------------------------------------------------------

    public static function stageLabel(?string $stage): string
    {
        return match ($stage) {
            'direct_manager', 'school_principal' => 'مدير المدرسة',
            'leaves_officer' => 'مسؤول الإجازات',
            'hr_affairs'     => 'شؤون عاملين',
            'admin_manager'  => 'مدير الإدارة',
            default => $stage ? str_replace('_', ' ', $stage) : '—',
        };
    }

    public static function pendingApprovalLabel(?string $stage): string
    {
        return match ($stage) {
            'leaves_officer'  => 'بانتظار اعتماد مسؤول الإجازات',
            'hr_affairs'      => 'بانتظار اعتماد شؤون العاملين',
            'admin_manager'   => 'بانتظار اعتماد مدير الإدارة',
            'school_principal', 'direct_manager' => 'بانتظار اعتماد مدير المدرسة',
            default => $stage ? str_replace('_', ' ', $stage) : '—',
        };
    }

    // -------------------------------------------------------------------------
    // Computed Attributes
    // -------------------------------------------------------------------------

    public function getCanCancelAttribute(): bool
    {
        return $this->status->canBeCancelled();
    }

    public function getCanEditAttribute(): bool
    {
        return $this->status->canBeEdited();
    }

    public function getIsApprovedAttribute(): bool
    {
        return $this->status === LeaveStatus::APPROVED;
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeWithStatus($query, LeaveStatus $status)
    {
        return $query->where('status', $status->value);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [
            LeaveStatus::SUBMITTED->value,
            LeaveStatus::IN_REVIEW->value,
        ]);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', LeaveStatus::APPROVED->value);
    }

    /**
     * Requests that overlap with the given date range for a specific employee.
     * Used for overlap validation in LeaveRequestService.
     */
    public function scopeOverlapping($query, int $employeeId, string $startDate, string $endDate, ?int $excludeId = null)
    {
        return $query->where('employee_id', $employeeId)
            ->whereNotIn('status', [
                LeaveStatus::REJECTED->value,
                LeaveStatus::CANCELLED->value,
            ])
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId));
    }

    /** Requests with no action taken for more than X days */
    public function scopeOverdue($query, int $days = 3)
    {
        return $query->whereIn('status', [
            LeaveStatus::SUBMITTED->value,
            LeaveStatus::IN_REVIEW->value,
        ])->where('submitted_at', '<', now()->subDays($days));
    }
}
