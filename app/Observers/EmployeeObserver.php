<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Facades\Log;

class EmployeeObserver
{
    public function __construct(private LeaveBalanceService $service) {}

    public function created(Employee $employee): void
    {
        try {
            $year = app(\App\Services\FiscalYearService::class)->currentFiscalYear();
            $this->service->accrueAnnual($employee, $year);
        } catch (\Throwable $e) {
            Log::warning('Failed to initialize balance for new employee', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
