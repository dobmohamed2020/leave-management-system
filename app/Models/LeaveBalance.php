<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveBalance extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'entitled',
        'carried_over',
        'used',
    ];

    protected $casts = [
        'year'         => 'integer',
        'entitled'     => 'integer',
        'carried_over' => 'integer',
        'used'         => 'integer',
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
            ->setDescriptionForEvent(function (string $eventName) {
                $employee = $this->employee?->full_name ?? "#{$this->employee_id}";
                $type     = $this->leaveType?->name ?? "#{$this->leave_type_id}";
                return "تم تحديث رصيد إجازة {$type} للموظف {$employee} - سنة {$this->year}";
            });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class)->latest();
    }

    // -------------------------------------------------------------------------
    // Computed Attributes
    // -------------------------------------------------------------------------

    /**
     * Remaining = entitled + carried_over - used
     * Never stored — always computed to stay in sync with the ledger.
     */
    public function getRemainingAttribute(): int
    {
        return max(0, (int) $this->entitled + (int) $this->carried_over - (int) $this->used);
    }

    /**
     * الأيام المستهلكة من الرصيد المُرحَّل أولاً (قاعدة استنفاد الرصيد الأقدم).
     */
    public function getUsedFromCarriedAttribute(): int
    {
        return min((int) $this->used, (int) $this->carried_over);
    }

    /**
     * المتبقي من الرصيد المُرحَّل.
     */
    public function getRemainingCarriedAttribute(): int
    {
        return max(0, (int) $this->carried_over - $this->used_from_carried);
    }

    /**
     * الأيام المستهلكة من الرصيد المستحق للسنة الحالية (بعد استنفاد المُرحَّل).
     */
    public function getUsedFromEntitledAttribute(): int
    {
        return max(0, (int) $this->used - (int) $this->carried_over);
    }

    /**
     * المتبقي من الرصيد المستحق للسنة الحالية.
     */
    public function getRemainingEntitledAttribute(): int
    {
        return max(0, (int) $this->entitled - $this->used_from_entitled);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    public function scopeCurrentYear($query)
    {
        return $query->where('year', now()->year);
    }
}
