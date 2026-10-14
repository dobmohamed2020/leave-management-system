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

it('verifies Point 9: NormalizeArabicNumbers middleware converts Arabic numerals to English digits in requests', function () {
    $middleware = new \App\Http\Middleware\NormalizeArabicNumbers();

    $request = \Illuminate\Http\Request::create('/test', 'POST', [
        'phone'         => '٠١٠١٢٣٤٥٦٧٨',
        'days'          => '٥',
        'employee_code' => 'EMP-١٠٠١',
        'nested'        => [
            'amount' => '١٢٣.٤٥',
            'year'   => '٢٠٢٦',
        ],
    ]);

    $middleware->handle($request, function ($req) {
        expect($req->input('phone'))->toBe('01012345678')
            ->and($req->input('days'))->toBe('5')
            ->and($req->input('employee_code'))->toBe('EMP-1001')
            ->and($req->input('nested.amount'))->toBe('123.45')
            ->and($req->input('nested.year'))->toBe('2026');

        return new \Symfony\Component\HttpFoundation\Response();
    });
});

it('verifies Point 10: validation messages and attribute names are in Arabic', function () {
    $validator = \Illuminate\Support\Facades\Validator::make([], [
        'start_date' => 'required',
        'days'       => 'required|integer|min:1',
        'email'      => 'required|email',
    ]);

    expect($validator->fails())->toBeTrue();
    $messages = $validator->errors()->all();

    expect($messages)->toContain('حقل تاريخ البداية مطلوب.')
        ->and($messages)->toContain('حقل عدد الأيام مطلوب.')
        ->and($messages)->toContain('حقل البريد الإلكتروني مطلوب.');
});

it('verifies Point 11: department manager (مدير الإدارة) can restore soft-deleted employee', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $adminUser = $ctx['adminUser'];
    $schoolUser = $ctx['schoolUser'];

    // Assign role 'مدير الإدارة' to adminUser and 'مدير مدرسة' to schoolUser
    $adminRole = \Spatie\Permission\Models\Role::firstOrCreate([
        'name'            => 'مدير الإدارة',
        'organization_id' => $ctx['adminOrg']->id,
        'guard_name'      => 'web',
    ]);
    $schoolRole = \Spatie\Permission\Models\Role::firstOrCreate([
        'name'            => 'مدير مدرسة',
        'organization_id' => $ctx['school']->id,
        'guard_name'      => 'web',
    ]);
    $adminUser->setOrganizationTeam();
    $adminUser->assignRole($adminRole);
    $schoolUser->setOrganizationTeam();
    $schoolUser->assignRole($schoolRole);

    // Soft delete employee
    $employee->delete();
    expect($employee->fresh()->trashed())->toBeTrue();

    // Verify policy: admin manager can restore, school user cannot
    $policy = app(\App\Policies\EmployeePolicy::class);
    expect($policy->restore($adminUser, $employee))->toBeTrue()
        ->and($policy->restore($schoolUser, $employee))->toBeFalse();

    // Restore employee
    $employee->restore();
    $employee->update(['is_active' => true]);

    expect($employee->fresh()->trashed())->toBeFalse()
        ->and($employee->fresh()->is_active)->toBeTrue();
});

it('verifies Point 12: department manager (مدير الإدارة) can restore soft-deleted leave request to draft', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $adminUser = $ctx['adminUser'];
    $schoolUser = $ctx['schoolUser'];
    $regularType = $ctx['regularType'];

    $adminRole = \Spatie\Permission\Models\Role::firstOrCreate([
        'name'            => 'مدير الإدارة',
        'organization_id' => $ctx['adminOrg']->id,
        'guard_name'      => 'web',
    ]);
    $schoolRole = \Spatie\Permission\Models\Role::firstOrCreate([
        'name'            => 'مدير مدرسة',
        'organization_id' => $ctx['school']->id,
        'guard_name'      => 'web',
    ]);
    $adminUser->setOrganizationTeam();
    $adminUser->assignRole($adminRole);
    $schoolUser->setOrganizationTeam();
    $schoolUser->assignRole($schoolRole);

    $request = LeaveRequest::create([
        'employee_id'     => $employee->id,
        'organization_id' => $ctx['school']->id,
        'leave_type_id'   => $regularType->id,
        'start_date'      => now()->addDays(5)->toDateString(),
        'end_date'        => now()->addDays(7)->toDateString(),
        'days'            => 2,
        'status'          => LeaveStatus::CANCELLED,
        'created_by'      => $adminUser->id,
    ]);

    // Soft delete request
    $request->delete();
    expect($request->fresh()->trashed())->toBeTrue();

    // Verify policy
    $policy = app(\App\Policies\LeaveRequestPolicy::class);
    expect($policy->restore($adminUser, $request))->toBeTrue()
        ->and($policy->restore($schoolUser, $request))->toBeFalse();

    // Restore via LeaveRequestService
    $service = app(\App\Services\LeaveRequestService::class);
    $restored = $service->restore($request, $adminUser);

    expect($restored->trashed())->toBeFalse()
        ->and($restored->status)->toBe(LeaveStatus::DRAFT)
        ->and($restored->current_stage)->toBeNull();
});

it('verifies Point 13: restore from normal deletion in DeletionRequest updates request to restored and restores target', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $adminUser = $ctx['adminUser'];
    $schoolUser = $ctx['schoolUser'];

    $adminRole = \Spatie\Permission\Models\Role::firstOrCreate([
        'name'            => 'مدير الإدارة',
        'organization_id' => $ctx['adminOrg']->id,
        'guard_name'      => 'web',
    ]);
    $adminUser->setOrganizationTeam();
    $adminUser->assignRole($adminRole);

    // 1. Create a deletion request approved as soft_delete
    $employee->delete();
    $delReq = DeletionRequest::create([
        'organization_id' => $ctx['school']->id,
        'model_type'      => 'employee',
        'model_id'        => $employee->id,
        'title'           => "طلب حذف الموظف {$employee->full_name}",
        'reason'          => 'نقل الموظف',
        'requested_by'    => $schoolUser->id,
        'status'          => 'approved',
        'action_type'     => 'soft_delete',
        'reviewed_by'     => $adminUser->id,
        'reviewed_at'     => now(),
    ]);

    expect($employee->fresh()->trashed())->toBeTrue()
        ->and($delReq->isApproved())->toBeTrue();

    // 2. Department manager restores the deletion
    $target = $delReq->getTargetRecord();
    expect($target)->not->toBeNull()
        ->and($target->trashed())->toBeTrue();

    $target->restore();
    $target->update(['is_active' => true]);

    $delReq->update([
        'status'      => 'restored',
        'admin_notes' => 'تمت استعادة الموظف من الحذف العادي بواسطة مدير الإدارة',
        'reviewed_by' => $adminUser->id,
        'reviewed_at' => now(),
    ]);

    expect($delReq->isRestored())->toBeTrue()
        ->and($employee->fresh()->trashed())->toBeFalse()
        ->and($employee->fresh()->is_active)->toBeTrue();
});

it('verifies Point 14: records employee promotions and career grades history with decision numbers and dates', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $adminUser = $ctx['adminUser'];

    // 1. Record an initial promotion
    $promo1 = $employee->recordPromotion(
        grade: 'معلم أول',
        entitlementGrade: null,
        promotionDate: '2020-09-01',
        decisionNumber: 'قرار وزاري رقم 100 لسنة 2020',
        notes: 'ترقية دورية',
        userId: $adminUser->id
    );

    // 2. Record a subsequent promotion
    $promo2 = $employee->recordPromotion(
        grade: 'معلم أول أ',
        entitlementGrade: null,
        promotionDate: '2024-03-01',
        decisionNumber: 'قرار وزاري رقم 250 لسنة 2024',
        notes: 'ترقية بالأقدمية',
        userId: $adminUser->id
    );

    $employee->refresh();

    // Check employee current fields
    expect($employee->grade)->toBe('معلم أول أ')
        ->and($employee->promotion_date->format('Y-m-d'))->toBe('2024-03-01')
        ->and($employee->promotion_decision_number)->toBe('قرار وزاري رقم 250 لسنة 2024');

    // Check promotions history relationship
    $history = $employee->promotions;
    expect($history)->toHaveCount(2)
        ->and($history->first()->grade)->toBe('معلم أول أ')
        ->and($history->last()->grade)->toBe('معلم أول');
});

it('verifies Point 15: EmployeeReportService compiles comprehensive report including promotions, isolated carried balances, requests, and audit trail', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $regularType = $ctx['regularType'];
    $adminUser = $ctx['adminUser'];

    // 1. Setup promotion
    $employee->recordPromotion(
        grade: 'كبير معلمين',
        entitlementGrade: null,
        promotionDate: '2023-01-15',
        decisionNumber: 'قرار رقم 44',
        notes: 'ترقية ممتازة',
        userId: $adminUser->id
    );

    // 2. Setup balance with isolated carried-over
    $fiscalYear = app(\App\Services\FiscalYearService::class)->currentFiscalYear();
    LeaveBalance::updateOrCreate(
        [
            'employee_id'   => $employee->id,
            'leave_type_id' => $regularType->id,
            'year'          => $fiscalYear,
        ],
        [
            'entitled'      => 30,
            'carried_over'  => 10,
            'used'          => 5,
            'used_carried'  => 3,
        ]
    );

    // 3. Create leave request
    LeaveRequest::withoutGlobalScopes()->create([
        'number'              => 'LV-REP-001',
        'employee_id'         => $employee->id,
        'organization_id'     => $ctx['school']->id,
        'leave_type_id'       => $regularType->id,
        'deduct_from_carried' => true,
        'start_date'          => '2026-11-01',
        'end_date'            => '2026-11-03',
        'days'                => 3,
        'status'              => LeaveStatus::APPROVED->value,
        'created_by'          => $adminUser->id,
    ]);

    // 4. Run report service
    $service = app(\App\Services\EmployeeReportService::class);
    $data = $service->getReportData($employee);

    expect($data)->toHaveKeys(['employee', 'basicInfo', 'promotionsData', 'balancesData', 'requestsData', 'auditTrail'])
        ->and($data['basicInfo']['full_name'])->toBe($employee->full_name)
        ->and($data['promotionsData']['current_grade'])->toBe('كبير معلمين')
        ->and($data['promotionsData']['decision_number'])->toBe('قرار رقم 44')
        ->and($data['promotionsData']['promotions_history'])->toHaveCount(1)
        ->and($data['balancesData']['regular']['carried_total'])->toBe(10)
        ->and($data['balancesData']['regular']['used_carried'])->toBe(3)
        ->and($data['balancesData']['regular']['remaining_carried'])->toBe(7)
        ->and($data['requestsData']['approved_count'])->toBe(1)
        ->and($data['auditTrail'])->toBeArray();
});

it('verifies Point 16: authenticated route /employee-report/{id} returns comprehensive printable Arabic report and enforces organization authorization', function () {
    $ctx = setupRequirementsTest();
    $employee = $ctx['employee'];
    $adminUser = $ctx['adminUser'];

    // 1. Guest is redirected / unauthorized
    $guestResponse = $this->get("/employee-report/{$employee->id}");
    $guestResponse->assertRedirect();

    // 2. Authorized admin user gets 200 and native Arabic print view
    $authResponse = $this->actingAs($adminUser)->get("/employee-report/{$employee->id}");
    $authResponse->assertOk()
        ->assertSee($employee->full_name)
        ->assertSee('بيان حالة وإجازات وترقيات موظف شامل')
        ->assertSee('الرصيد المُرحَّل')
        ->assertSee('الدرجات الوظيفية وسجل الترقيات');

    // 3. User from unrelated organization is rejected with 403
    $otherOrg = Organization::withoutGlobalScopes()->create([
        'name'      => 'إدارة إسنا التعليمية',
        'type'      => OrganizationType::ADMINISTRATION,
        'code'      => 'ADM-ESNA',
        'path'      => '/999/',
        'depth'     => 1,
        'is_active' => true,
    ]);

    $otherUser = User::create([
        'name'            => 'موظف إدارة أخرى',
        'email'           => 'other_admin@example.com',
        'password'        => bcrypt('password'),
        'organization_id' => $otherOrg->id,
        'is_active'       => true,
    ]);

    $forbiddenResponse = $this->actingAs($otherUser)->get("/employee-report/{$employee->id}");
    $forbiddenResponse->assertForbidden();
});



