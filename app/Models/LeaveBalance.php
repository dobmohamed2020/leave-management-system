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
        'used_carried',
    ];

    protected $casts = [
        'year'         => 'integer',
        'entitled'     => 'integer',
        'carried_over' => 'integer',
        'used'         => 'integer',
        'used_carried' => 'integer',
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
     * المتبقي من الرصيد السنوي المستحق (منعزل تماماً عن الرصيد المُرحَّل).
     * Remaining Entitled = entitled - used
     */
    public function getRemainingEntitledAttribute(): int
    {
        return max(0, (int) $this->entitled - (int) $this->used);
    }

    /**
     * الأيام المستهلكة من الرصيد المُرحَّل.
     */
    public function getUsedFromCarriedAttribute(): int
    {
        return (int) ($this->used_carried ?? 0);
    }

    /**
     * المتبقي من الرصيد المُرحَّل (منعزل تماماً عن رصيد السنة).
     * Remaining Carried = carried_over - used_carried
     */
    public function getRemainingCarriedAttribute(): int
    {
        return max(0, (int) $this->carried_over - (int) $this->used_carried);
    }

    /**
     * الأيام المستهلكة من الرصيد المستحق للسنة الحالية.
     */
    public function getUsedFromEntitledAttribute(): int
    {
        return (int) $this->used;
    }

    /**
     * إجمالي الأيام المستهلكة (السنوي + المُرحَّل).
     */
    public function getTotalUsedAttribute(): int
    {
        return (int) $this->used + (int) $this->used_carried;
    }

    /**
     * Remaining = remaining_entitled + remaining_carried
     */
    public function getRemainingAttribute(): int
    {
        return $this->remaining_entitled + $this->remaining_carried;
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
        $fiscalYear = app(\App\Services\FiscalYearService::class)->currentFiscalYear();
        return $query->where('year', $fiscalYear);
    }
}
