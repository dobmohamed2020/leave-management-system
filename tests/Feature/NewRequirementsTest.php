<?php

use App\Enums\LeaveStatus;
use App\Enums\OrganizationType;
use App\Enums\TransactionType;
use App\Models\DeletionRequest;
use App\Models\Employee;
use App\Models\EntitlementGrade;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use App\Services\CascadeDeletionService;
use App\Services\FiscalYearService;
use App\Services\LeaveBalanceService;
use App\Services\LeaveRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupRequirementsTest(): array
{
    $directorate = Organization::withoutGlobalScopes()->create([
        'name'      => 'مديرية التربية والتعليم بالأقصر',
        'type'      => OrganizationType::DIRECTORATE,
        'code'      => 'DIR-LUXOR',
        'path'      => '/1/',
        'depth'     => 0,
        'is_active' => true,
    ]);

    $adminOrg = Organization::withoutGlobalScopes()->create([
        'name'      => 'إدارة أرمنت التعليمية',
        'type'      => OrganizationType::ADMINISTRATION,
        'code'      => 'ADM-ARMANT',
        'parent_id' => $directorate->id,
        'path'      => "/{$directorate->id}/2/",
        'depth'     => 1,
        'is_active' => true,
    ]);

    $school = Organization::withoutGlobalScopes()->create([
        'name'      => 'مدرسة أرمنت الابتدائية',
        'type'      => OrganizationType::SCHOOL,
        'code'      => 'SCH-ARMANT-01',
        'parent_id' => $adminOrg->id,
        'path'      => "/{$directorate->id}/{$adminOrg->id}/3/",
        'depth'     => 2,
        'is_active' => true,
    ]);

    $adminUser = User::create([
        'name'            => 'مدير الإدارة',
        'email'           => 'admin_manager@example.com',
        'password'        => bcrypt('password'),
        'organization_id' => $adminOrg->id,
        'is_active'       => true,
    ]);

    $schoolUser = User::create([
        'name'            => 'مدير المدرسة',
        'email'           => 'school_principal@example.com',
        'password'        => bcrypt('password'),
        'organization_id' => $school->id,
        'is_active'       => true,
    ]);

    $regularType = LeaveType::create([
        'code'                 => 'regular',
        'name'                 => 'إجازة اعتيادية',
        'deducts_balance'      => true,
        'yearly_entitlement'   => 30,
        'max_days_per_request' => null,
        'is_active'            => true,
    ]);

    $employee = Employee::withoutGlobalScopes()->create([
        'organization_id' => $school->id,
        'employee_code'   => 'EMP-1001',
        'full_name'       => 'أحمد محمود علي',
        'hire_date'       => '2015-01-01',
        'is_active'       => true,
    ]);

    return compact('directorate', 'adminOrg', 'school', 'adminUser', 'schoolUser', 'regularType', 'employee');
}

it('verifies Point 1: carried-over balance is completely isolated from annual balance', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $regularType = $ctx['regularType'];

    $fiscalYear = app(FiscalYearService::class)->currentFiscalYear();

    $balance = LeaveBalance::updateOrCreate(
        [
            'employee_id'   => $employee->id,
            'leave_type_id' => $regularType->id,
            'year'          => $fiscalYear,
        ],
        [
            'entitled'      => 30,
            'carried_over'  => 15,
            'used'          => 0,
            'used_carried'  => 0,
        ]
    );

    expect($balance->remaining_entitled)->toBe(30)
        ->and($balance->remaining_carried)->toBe(15)
        ->and($balance->remaining)->toBe(45);
});

it('verifies Point 3: leaves can be deducted specifically from carried-over balance', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $regularType = $ctx['regularType'];
    $adminUser = $ctx['adminUser'];

    $fiscalYear = app(FiscalYearService::class)->currentFiscalYear();

    $balance = LeaveBalance::updateOrCreate(
        [
            'employee_id'   => $employee->id,
            'leave_type_id' => $regularType->id,
            'year'          => $fiscalYear,
        ],
        [
            'entitled'      => 30,
            'carried_over'  => 15,
            'used'          => 0,
            'used_carried'  => 0,
        ]
    );

    $service = app(LeaveBalanceService::class);

    // Create a request marked as deduct_from_carried
    $request = LeaveRequest::withoutGlobalScopes()->create([
        'number'              => 'LV-TEST-001',
        'employee_id'         => $employee->id,
        'organization_id'     => $ctx['school']->id,
        'leave_type_id'       => $regularType->id,
        'deduct_from_carried' => true,
        'start_date'          => '2026-10-15',
        'end_date'            => '2026-10-20',
        'days'                => 5,
        'status'              => LeaveStatus::APPROVED,
        'created_by'          => $adminUser->id,
    ]);

    $service->deduct($balance, 5, $request, $adminUser);
    $balance->refresh();

    // Carried-over balance must be decremented, and annual used must stay 0!
    expect($balance->used_carried)->toBe(5)
        ->and($balance->used)->toBe(0)
        ->and($balance->remaining_carried)->toBe(10)
        ->and($balance->remaining_entitled)->toBe(30);

    // Now deduct 4 days from annual
    $requestAnnual = LeaveRequest::withoutGlobalScopes()->create([
        'number'              => 'LV-TEST-002',
        'employee_id'         => $employee->id,
        'organization_id'     => $ctx['school']->id,
        'leave_type_id'       => $regularType->id,
        'deduct_from_carried' => false,
        'start_date'          => '2026-10-22',
        'end_date'            => '2026-10-26',
        'days'                => 4,
        'status'              => LeaveStatus::APPROVED,
        'created_by'          => $adminUser->id,
    ]);

    $service->deduct($balance, 4, $requestAnnual, $adminUser);
    $balance->refresh();

    expect($balance->used)->toBe(4)
        ->and($balance->used_carried)->toBe(5)
        ->and($balance->remaining_entitled)->toBe(26)
        ->and($balance->remaining_carried)->toBe(10)
        ->and($balance->remaining)->toBe(36);
});

it('verifies Point 2: 30-6 fiscal rollover carries over remaining unused days into carried-over balance', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $regularType = $ctx['regularType'];

    // Year 2024 (2024/2025 ending on 30-06-2025)
    $fromBalance = LeaveBalance::create([
        'employee_id'   => $employee->id,
        'leave_type_id' => $regularType->id,
        'year'          => 2024,
        'entitled'      => 30,
        'carried_over'  => 5,
        'used'          => 10, // 20 remaining entitled
        'used_carried'  => 2,  // 3 remaining carried
    ]);

    $service = app(LeaveBalanceService::class);
    // Rollover from 2024 into 2025
    $service->carryOver($employee, 2024, 2025);

    $toBalance = LeaveBalance::where('employee_id', $employee->id)->where('year', 2025)->first();

    // Remaining annual (20) + remaining carried (3) = 23 days carried forward!
    expect($toBalance)->not->toBeNull()
        ->and($toBalance->carried_over)->toBe(23)
        ->and($toBalance->used_carried)->toBe(0);
});

it('verifies Point 4: permanent cascade hard deletion cleanly cleans up employee and all relations', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $adminUser = $ctx['adminUser'];
    $regularType = $ctx['regularType'];

    $user = User::create([
        'name'            => 'حساب دخول للموظف',
        'email'           => 'emp_login@example.com',
        'password'        => bcrypt('password'),
        'organization_id' => $ctx['school']->id,
        'is_active'       => true,
    ]);
    $employee->update(['user_id' => $user->id]);

    $balance = LeaveBalance::updateOrCreate(
        [
            'employee_id'   => $employee->id,
            'leave_type_id' => $regularType->id,
            'year'          => 2026,
        ],
        [
            'entitled'      => 30,
            'carried_over'  => 10,
            'used'          => 5,
            'used_carried'  => 2,
        ]
    );

    $request = LeaveRequest::withoutGlobalScopes()->create([
        'number'          => 'LV-CASCADE-001',
        'employee_id'     => $employee->id,
        'organization_id' => $ctx['school']->id,
        'leave_type_id'   => $regularType->id,
        'start_date'      => '2026-10-01',
        'end_date'        => '2026-10-05',
        'days'            => 4,
        'status'          => LeaveStatus::APPROVED,
        'created_by'      => $adminUser->id,
    ]);

    LeaveRequestStep::create([
        'leave_request_id' => $request->id,
        'step_order'       => 1,
        'stage'            => 'school_principal',
        'status'           => 'approved',
        'acted_by'         => $adminUser->id,
    ]);

    LeaveBalanceTransaction::create([
        'leave_balance_id' => $balance->id,
        'leave_request_id' => $request->id,
        'type'             => TransactionType::DEDUCTION,
        'days'             => 4,
        'created_by'       => $adminUser->id,
    ]);

    // Perform cascade delete
    $cascade = app(CascadeDeletionService::class);
    $cascade->deleteEmployeePermanently($employee, $adminUser);

    expect(Employee::withoutGlobalScopes()->withTrashed()->find($employee->id))->toBeNull()
        ->and(LeaveRequest::withoutGlobalScopes()->withTrashed()->find($request->id))->toBeNull()
        ->and(LeaveBalance::find($balance->id))->toBeNull()
        ->and(LeaveBalanceTransaction::where('leave_balance_id', $balance->id)->count())->toBe(0)
        ->and(User::find($user->id))->toBeNull();
});

it('verifies Point 5: school deletion request workflow', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $schoolUser = $ctx['schoolUser'];
    $adminUser = $ctx['adminUser'];

    // 1. School user sends deletion request
    $delReq = DeletionRequest::create([
        'organization_id' => $ctx['school']->id,
        'model_type'      => 'employee',
        'model_id'        => $employee->id,
        'title'           => "طلب حذف الموظف {$employee->full_name}",
        'details'         => ['code' => $employee->employee_code],
        'reason'          => 'نقل الموظف إلى محافظة أخرى خارج المديرية',
        'requested_by'    => $schoolUser->id,
        'status'          => 'pending',
    ]);

    expect($delReq->isPending())->toBeTrue();

    // 2. Admin approves deletion request
    $target = $delReq->getTargetRecord();
    expect($target)->not->toBeNull();

    $target->delete(); // Soft delete as approved
    $delReq->update([
        'status'      => 'approved',
        'admin_notes' => 'تمت الموافقة على طلب الحذف للنقل',
        'reviewed_by' => $adminUser->id,
        'reviewed_at' => now(),
    ]);

    expect($delReq->isApproved())->toBeTrue()
        ->and($employee->fresh()->trashed())->toBeTrue();
});

it('verifies Point 6: rejects leave request with past date', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $adminUser = $ctx['adminUser'];
    $regularType = $ctx['regularType'];

    $service = app(LeaveRequestService::class);

    expect(function () use ($service, $employee, $adminUser, $regularType) {
        $service->create([
            'employee_id'   => $employee->id,
            'leave_type_id' => $regularType->id,
            'start_date'    => now()->subDays(2)->toDateString(),
            'end_date'      => now()->subDay()->toDateString(),
            'days'          => 2,
        ], $adminUser);
    })->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('verifies Point 7: rejects leave request when exceeding remaining balance (annual and carried)', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $adminUser = $ctx['adminUser'];
    $regularType = $ctx['regularType'];
    $fiscalYear = app(FiscalYearService::class)->currentFiscalYear();

    // 5 annual, 3 carried
    LeaveBalance::updateOrCreate(
        [
            'employee_id'   => $employee->id,
            'leave_type_id' => $regularType->id,
            'year'          => $fiscalYear,
        ],
        [
            'entitled'      => 5,
            'carried_over'  => 3,
            'used'          => 0,
            'used_carried'  => 0,
        ]
    );

    $service = app(LeaveRequestService::class);

    // 1. Regular leave requesting 6 days (more than 5 entitled) must fail!
    expect(function () use ($service, $employee, $adminUser, $regularType) {
        $service->create([
            'employee_id'         => $employee->id,
            'leave_type_id'       => $regularType->id,
            'deduct_from_carried' => false,
            'start_date'          => now()->addDays(2)->toDateString(),
            'end_date'            => now()->addDays(7)->toDateString(),
            'days'                => 6,
        ], $adminUser);
    })->toThrow(\Illuminate\Validation\ValidationException::class);

    // 2. Carried leave requesting 4 days (more than 3 carried) must fail!
    expect(function () use ($service, $employee, $adminUser, $regularType) {
        $service->create([
            'employee_id'         => $employee->id,
            'leave_type_id'       => $regularType->id,
            'deduct_from_carried' => true,
            'start_date'          => now()->addDays(10)->toDateString(),
            'end_date'            => now()->addDays(13)->toDateString(),
            'days'                => 4,
        ], $adminUser);
    })->toThrow(\Illuminate\Validation\ValidationException::class);

    // 3. Requesting within balance must succeed!
    $validRequest = $service->create([
        'employee_id'         => $employee->id,
        'leave_type_id'       => $regularType->id,
        'deduct_from_carried' => true,
        'start_date'          => now()->addDays(15)->toDateString(),
        'end_date'            => now()->addDays(17)->toDateString(),
        'days'                => 3,
    ], $adminUser);

    expect($validRequest->request)->not->toBeNull()
        ->and($validRequest->request->deduct_from_carried)->toBeTrue()
        ->and((int) $validRequest->request->days)->toBe(3);
});

it('verifies Point 8: separate routes and pages exist for regular vs carried leave requests', function () {
    $pages = \App\Filament\Resources\LeaveRequestResource::getPages();

    expect($pages)->toHaveKeys(['index', 'create', 'create-carried', 'edit', 'view']);
    expect($pages['create']->getPage())->toBe(\App\Filament\Resources\LeaveRequestResource\Pages\CreateLeaveRequest::class);
    expect($pages['create-carried']->getPage())->toBe(\App\Filament\Resources\LeaveRequestResource\Pages\CreateCarriedLeaveRequest::class);
});

