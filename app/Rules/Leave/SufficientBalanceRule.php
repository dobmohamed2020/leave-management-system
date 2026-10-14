<?php

namespace App\Rules\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\LeaveBalanceService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that the employee has sufficient leave balance.
 *
 * When config('leave.warn_only_insufficient_balance') = true:
 *   → Does NOT call $fail. Exposes $warning string for the service to relay to the UI.
 *
 * When config('leave.warn_only_insufficient_balance') = false:
 *   → Calls $fail with an Arabic error message.
 */
class SufficientBalanceRule implements ValidationRule
{
    public ?string $warning = null;

    public function __construct(
        private readonly Employee $employee,
        private readonly LeaveType $leaveType,
        private readonly int $year,
        private readonly bool $deductFromCarried = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Types that don't deduct balance always pass
        if (! $this->leaveType->deducts_balance) {
            return;
        }

        $balance = $this->employee->balanceFor($this->leaveType->id, $this->year);
        $requested = (float) $value;

        if ($this->deductFromCarried) {
            $remaining = $balance ? (float) $balance->remaining_carried : 0.0;
            $balanceName = 'الرصيد المُرحَّل';
        } else {
            $remaining = $balance ? (float) $balance->remaining_entitled : 0.0;
            $balanceName = 'رصيد السنة الحالية';
        }

        if ($remaining >= $requested) {
            return;
        }

        $msg = sprintf(
            '%s المتبقي (%.1f يوم) أقل من المطلوب (%.1f يوم).',
            $balanceName,
            $remaining,
            $requested
        );

        if (config('leave.warn_only_insufficient_balance', true)) {
            $this->warning = $msg . ' سيستمر الطلب مع الإشارة إلى النقص.';
        } else {
            $fail($msg);
        }
    }
}
