<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Models\DeletionRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceTransaction;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CascadeDeletionService
{
    /**
     * Permanent (hard) cascade deletion of an Employee and all related data.
     */
    public function deleteEmployeePermanently(Employee $employee, User $actedBy): void
    {
        DB::transaction(function () use ($employee, $actedBy) {
            $employeeName = $employee->full_name;
            $employeeId   = $employee->id;
            $linkedUserId = $employee->user_id;

            // 1. Clean up all leave requests owned by this employee
            $leaveRequests = LeaveRequest::withoutGlobalScopes()
                ->withTrashed()
                ->where('employee_id', $employeeId)
                ->get();

            foreach ($leaveRequests as $req) {
                // Delete attachments and their physical files
                foreach ($req->attachments as $att) {
                    if ($att->path && Storage::disk('public')->exists($att->path)) {
                        Storage::disk('public')->delete($att->path);
                    }
                    $att->delete();
                }

                // Delete approval steps
                $req->steps()->delete();

                // Delete balance transactions tied to this request
                LeaveBalanceTransaction::where('leave_request_id', $req->id)->delete();

                // Delete any deletion request for this leave request
                DeletionRequest::where('model_type', 'leave_request')
                    ->where('model_id', $req->id)
                    ->delete();

                // Force delete the leave request
                $req->forceDelete();
            }

            // 2. Clear substitute employee reference in other requests
            LeaveRequest::withoutGlobalScopes()
                ->where('substitute_employee_id', $employeeId)
                ->update(['substitute_employee_id' => null]);

            // 3. Delete leave balances and their ledger transactions
            $balances = LeaveBalance::where('employee_id', $employeeId)->get();
            if ($balances->isNotEmpty()) {
                LeaveBalanceTransaction::whereIn('leave_balance_id', $balances->pluck('id'))->delete();
                LeaveBalance::where('employee_id', $employeeId)->delete();
            }

            // 4. Delete pending deletion requests for this employee
            DeletionRequest::where('model_type', 'employee')
                ->where('model_id', $employeeId)
                ->delete();

            // 5. Force delete the employee record
            $employee->forceDelete();

            // 6. Delete linked user account if exists
            if ($linkedUserId) {
                $user = User::find($linkedUserId);
                if ($user) {
                    $user->syncRoles([]);
                    $user->syncPermissions([]);
                    $user->delete();
                }
            }

            Log::info("Permanent cascade delete executed for employee [{$employeeName}] ID: {$employeeId} by user [{$actedBy->name}]");
        });
    }

    /**
     * Permanent (hard) cascade deletion of a LeaveRequest and all related data.
     */
    public function deleteLeaveRequestPermanently(LeaveRequest $request, User $actedBy): void
    {
        DB::transaction(function () use ($request, $actedBy) {
            $reqNumber = $request->number;
            $reqId     = $request->id;

            // If request was approved and deducted balance, refund the days before hard delete
            if ($request->status === LeaveStatus::APPROVED && $request->leaveType?->deducts_balance) {
                try {
                    $fiscalYear = app(FiscalYearService::class)->fiscalYearFor($request->start_date);
                    $balance = app(LeaveBalanceService::class)->getOrCreateBalance(
                        $request->employee,
                        $request->leaveType,
                        $fiscalYear
                    );
                    app(LeaveBalanceService::class)->refund($balance, (int) $request->days, $request, $actedBy);
                } catch (\Throwable $e) {
                    Log::warning("Could not auto-refund balance before permanent delete of request [{$reqNumber}]: " . $e->getMessage());
                }
            }

            // Delete physical attachment files and attachment records
            foreach ($request->attachments as $att) {
                if ($att->path && Storage::disk('public')->exists($att->path)) {
                    Storage::disk('public')->delete($att->path);
                }
                $att->delete();
            }

            // Delete steps
            $request->steps()->delete();

            // Delete related balance transactions
            LeaveBalanceTransaction::where('leave_request_id', $reqId)->delete();

            // Delete deletion requests
            DeletionRequest::where('model_type', 'leave_request')
                ->where('model_id', $reqId)
                ->delete();

            // Force delete the record
            $request->forceDelete();

            Log::info("Permanent delete executed for leave request [{$reqNumber}] ID: {$reqId} by user [{$actedBy->name}]");
        });
    }

    /**
     * Permanent cascade deletion of a system user.
     */
    public function deleteUserPermanently(User $user, User $actedBy): void
    {
        DB::transaction(function () use ($user, $actedBy) {
            $userName = $user->name;
            $userId   = $user->id;

            // If user has an attached employee, delete employee first
            if ($user->employee) {
                $this->deleteEmployeePermanently($user->employee, $actedBy);
            }

            // Null out any created_by / decided_by / acted_by references
            LeaveRequest::where('created_by', $userId)->update(['created_by' => $actedBy->id]);
            LeaveRequest::where('decided_by', $userId)->update(['decided_by' => null]);

            $user->syncRoles([]);
            $user->syncPermissions([]);
            $user->delete();

            Log::info("Permanent delete executed for user [{$userName}] ID: {$userId} by user [{$actedBy->name}]");
        });
    }
}
