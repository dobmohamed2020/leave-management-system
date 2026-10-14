<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePromotion extends Model
{
    use HasFactory;

    protected $table = 'employee_promotions';

    protected $fillable = [
        'employee_id',
        'grade',
        'entitlement_grade',
        'promotion_date',
        'decision_number',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'promotion_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function entitlementGrade(): BelongsTo
    {
        return $this->belongsTo(EntitlementGrade::class, 'entitlement_grade', 'code');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * حساب المدة المنقضية منذ تاريخ الترقية (سنة، شهر).
     */
    public function getDurationSincePromotionLabelAttribute(): string
    {
        if (! $this->promotion_date) {
            return '—';
        }

        $diff = $this->promotion_date->diff(now());

        if ($diff->y === 0 && $diff->m === 0) {
            return 'أقل من شهر';
        }

        $parts = [];
        if ($diff->y > 0) {
            $parts[] = "{$diff->y} سنة";
        }
        if ($diff->m > 0) {
            $parts[] = "{$diff->m} شهر";
        }

        return implode(' و ', $parts);
    }
}
