<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeletionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'model_type',
        'model_id',
        'title',
        'details',
        'reason',
        'requested_by',
        'status',
        'action_type',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'details'     => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isRestored(): bool
    {
        return $this->status === 'restored';
    }

    public function getTargetRecord(): ?Model
    {
        if ($this->model_type === 'employee') {
            return Employee::withoutGlobalScopes()->withTrashed()->find($this->model_id);
        }

        if ($this->model_type === 'leave_request') {
            return LeaveRequest::withoutGlobalScopes()->withTrashed()->find($this->model_id);
        }

        return null;
    }
}
