<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEmployeeRequest;
use App\Http\Requests\Api\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\LeaveBalanceResource;
use App\Http\Resources\LeaveCarryoverRecordResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    use ApiResponse;

    // GET /api/employees
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $query = Employee::with('organization')->latest();

        if ($orgId = $request->query('organization_id')) {
            $query->where('organization_id', $orgId);
        }
        if ($name = $request->query('full_name')) {
            $query->where('full_name', 'like', "%{$name}%");
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($grade = $request->query('entitlement_grade')) {
            $query->where('entitlement_grade', $grade);
        }

        $paginated = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => EmployeeResource::collection($paginated)->resolve(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    // POST /api/employees
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $employee = Employee::create($request->validated());
        return $this->success(
            EmployeeResource::make($employee->load('organization')),
            'تم إضافة الموظف بنجاح.',
            201
        );
    }

    // GET /api/employees/{id}
    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->load(['organization', 'leaveBalances.leaveType']);
        return $this->success(EmployeeResource::make($employee));
    }

    // PUT /api/employees/{id}
    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $employee->update(array_filter($request->validated(), fn($v) => $v !== null));
        return $this->success(
            EmployeeResource::make($employee->fresh('organization')),
            'تم تحديث بيانات الموظف بنجاح.'
        );
    }

    // DELETE /api/employees/{id}
    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();
        return $this->success(null, 'تم حذف الموظف بنجاح.');
    }

    // GET /api/employees/{id}/carryover-records
    public function carryoverRecords(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $records = $employee->carryoverRecords()
            ->with('leaveType')
            ->orderByDesc('to_fiscal_year')
            ->get();

        return $this->success(
            LeaveCarryoverRecordResource::collection($records),
            'سجل ترحيلات الموظف.'
        );
    }

    // GET /api/employees/{id}/balances
    public function balances(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $fiscalYear = app(\App\Services\FiscalYearService::class)->currentFiscalYear();
        $balances = $employee->leaveBalances()
            ->with('leaveType')
            ->where('year', $fiscalYear)
            ->get();

        return $this->success(
            LeaveBalanceResource::collection($balances),
            'رصيد إجازات الموظف للسنة الحالية.'
        );
    }
}
