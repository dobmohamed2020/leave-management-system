<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveCarryoverRecord extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'from_fiscal_year',
        'to_fiscal_year',
        'days_carried',
        'entitlement_grade',
        'source',
        'leave_balance_transaction_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'from_fiscal_year' => 'integer',
            'to_fiscal_year'   => 'integer',
            'days_carried'     => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LeaveBalanceTransaction::class, 'leave_balance_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
