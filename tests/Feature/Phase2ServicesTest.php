<?php

use App\Enums\ApprovalRule;
use App\Enums\EntitlementGrade;
use App\Enums\LeaveStatus;
use App\Enums\StepStatus;
use App\Enums\TransactionType;
use App\Exceptions\LeaveRequestException;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkflowConfiguration;
use App\Notifications\LeaveRequestNotification;
use App\Rules\Leave\CasualBeforeRegularRule;
use App\Rules\Leave\MaxDaysPerRequestRule;
use App\Rules\Leave\NoOverlapRule;
use App\Rules\Leave\SufficientBalanceRule;
use App\Rules\Leave\WorkingDaysRule;
use App\Services\LeaveBalanceService;
use App\Services\LeaveRequestService;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// =============================================================================
// Helpers shared across Phase 2 tests
// =============================================================================

/**
 * Build a minimal school + employee + leave types + workflow config
 * ready for LeaveRequestService tests.
 */
function setupPhase2(): array
{
    test()->seed(\Database\Seeders\EntitlementGradeSeeder::class);

    $orgs = createHierarchy();

    $regular = LeaveType::factory()->regular()->create();
    $casual  = LeaveType::factory()->casual()->create();

    $employee = Employee::factory()
        ->inOrganization($orgs['school'])
        ->withGrade(EntitlementGrade::TEACHER_FIRST)  // 30 days
        ->create(['birth_date' => now()->subYears(35)]);

    $substitute = Employee::factory()
        ->inOrganization($orgs['school'])
        ->create(['full_name' => 'موظف بديل للاختبار']);

    $user = User::factory()->inOrganization($orgs['school'])->create();

    $workflowPerms = [
        'create_leave_request', 'edit_leave_request', 'submit_leave_request',
        'approve_leave_request', 'reject_leave_request', 'return_leave_request',
        'cancel_leave_request', 'view_leave_requests', 'view_employees',
    ];
    foreach ($workflowPerms as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }
    setPermissionsTeamId($orgs['school']->id);
    foreach (['مدير مدرسة', 'مسؤول الإجازات', 'مدير الإدارة'] as $roleName) {
        $role = Role::firstOrCreate([
            'name'            => $roleName,
            'guard_name'      => 'web',
            'organization_id' => $orgs['school']->id,
        ]);
        $role->syncPermissions($workflowPerms);
        $user->assignRole($role);
    }
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    // Balances
    LeaveBalance::create([
        'employee_id'   => $employee->id,
        'leave_type_id' => $regular->id,
        'year'          => now()->year,
        'entitled'      => 30,
        'carried_over'  => 0,
        'used'          => 0,
    ]);

    LeaveBalance::create([
        'employee_id'   => $employee->id,
        'leave_type_id' => $casual->id,
        'year'          => now()->year,
        'entitled'      => 7,
        'carried_over'  => 0,
        'used'          => 0,
    ]);

    // 3-stage workflow for this school
    WorkflowConfiguration::factory()->directManagerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->leavesOfficerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->adminManagerStage($orgs['school'])->create();

    return compact('orgs', 'regular', 'casual', 'employee', 'substitute', 'user');
}

// =============================================================================
// GROUP 1: LeaveBalanceService
// =============================================================================

describe('LeaveBalanceService', function () {

    it('getOrCreateBalance creates a balance with grade-based entitlement for regular leave', function () {
        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()
            ->inOrganization($orgs['school'])
            ->withGrade(EntitlementGrade::TEACHER_EXPERT) // 40 days
            ->create(['birth_date' => now()->subYears(35)]);

        $service = app(LeaveBalanceService::class);
        $balance = $service->getOrCreateBalance($emp, $lt, now()->year);

        expect($balance->entitled)->toBe(40);
        expect($balance->used)->toBe(0);
    });

    it('getOrCreateBalance returns existing record on second call', function () {
        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()->inOrganization($orgs['school'])->create();

        $service = app(LeaveBalanceService::class);
        $b1 = $service->getOrCreateBalance($emp, $lt, now()->year);
        $b2 = $service->getOrCreateBalance($emp, $lt, now()->year);

        expect($b1->id)->toBe($b2->id);
        expect(LeaveBalance::count())->toBe(1);
    });

    it('deduct increments used and appends a deduction transaction', function () {
        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()->inOrganization($orgs['school'])->create();
        $user = User::factory()->inOrganization($orgs['school'])->create();
        $req  = LeaveRequest::factory()->forEmployee($emp)->approved()->create([
            'leave_type_id' => $lt->id,
            'days'          => 5,
            'created_by'    => $user->id,
        ]);

        $service = app(LeaveBalanceService::class);
        $balance = LeaveBalance::factory()->forEmployee($emp)->forLeaveType($lt)->fresh()->create();

        $service->deduct($balance, 5, $req, $user);

        $balance->refresh();
        expect((float) $balance->used)->toBe(5.0);
        expect($balance->transactions()->where('type', TransactionType::DEDUCTION)->count())->toBe(1);
    });

    it('refund decrements used and appends a refund transaction', function () {
        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()->inOrganization($orgs['school'])->create();
        $user = User::factory()->inOrganization($orgs['school'])->create();
        $req  = LeaveRequest::factory()->forEmployee($emp)->approved()->create([
            'leave_type_id' => $lt->id,
            'days'          => 5,
            'created_by'    => $user->id,
        ]);

        $service = app(LeaveBalanceService::class);
        $balance = LeaveBalance::factory()->forEmployee($emp)->forLeaveType($lt)->create([
            'entitled' => 30,
            'used'     => 10,
        ]);

        $service->refund($balance, 5, $req, $user);

        $balance->refresh();
        expect((float) $balance->used)->toBe(5.0);
        expect($balance->transactions()->where('type', TransactionType::REFUND)->count())->toBe(1);
    });

    it('refund floors used at zero even if days exceed used', function () {
        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()->inOrganization($orgs['school'])->create();
        $user = User::factory()->inOrganization($orgs['school'])->create();
        $req  = LeaveRequest::factory()->forEmployee($emp)->approved()->create([
            'leave_type_id' => $lt->id,
            'days'          => 20,
            'created_by'    => $user->id,
        ]);

        $service = app(LeaveBalanceService::class);
        $balance = LeaveBalance::factory()->forEmployee($emp)->forLeaveType($lt)->create([
            'entitled' => 30,
            'used'     => 3, // less than refund amount
        ]);

        $service->refund($balance, 20, $req, $user);

        $balance->refresh();
        expect((float) $balance->used)->toBe(0.0);
    });

    it('adjust with positive days increments entitled', function () {
        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()->inOrganization($orgs['school'])->create();
        $user = User::factory()->inOrganization($orgs['school'])->create();

        $service = app(LeaveBalanceService::class);
        $balance = LeaveBalance::factory()->forEmployee($emp)->forLeaveType($lt)->create([
            'entitled' => 30,
            'used'     => 0,
        ]);

        $service->adjust($balance, 5, 'تعديل إداري', $user);

        $balance->refresh();
        expect((float) $balance->entitled)->toBe(35.0);
        expect($balance->transactions()->where('type', TransactionType::ADJUSTMENT)->count())->toBe(1);
    });

    it('adjust with negative days decrements used', function () {
        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()->inOrganization($orgs['school'])->create();
        $user = User::factory()->inOrganization($orgs['school'])->create();

        $service = app(LeaveBalanceService::class);
        $balance = LeaveBalance::factory()->forEmployee($emp)->forLeaveType($lt)->create([
            'entitled' => 30,
            'used'     => 10,
        ]);

        $service->adjust($balance, -3, 'تصحيح', $user);

        $balance->refresh();
        expect((float) $balance->used)->toBe(7.0);
    });

    it('carryOver moves remaining days to next year balance', function () {
        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()->inOrganization($orgs['school'])->create();

        $balance = LeaveBalance::create([
            'employee_id'   => $emp->id,
            'leave_type_id' => $lt->id,
            'year'          => 2025,
            'entitled'      => 30,
            'carried_over'  => 0,
            'used'          => 20,  // remaining = 10
        ]);

        $service = app(LeaveBalanceService::class);
        $service->carryOver($emp, 2025, 2026);

        $nextYear = LeaveBalance::where('employee_id', $emp->id)
            ->where('leave_type_id', $lt->id)
            ->where('year', 2026)
            ->first();

        expect($nextYear)->not->toBeNull();
        expect((float) $nextYear->carried_over)->toBe(10.0);
    });

    it('accrueAnnual sets entitled on all active leave types with entitlement', function () {
        $orgs = createHierarchy();
        $regular = LeaveType::factory()->regular()->create();
        $casual  = LeaveType::factory()->casual()->create();
        // sick has yearly_entitlement=180 but deducts_balance=false — still gets accrued
        $sick    = LeaveType::factory()->sick()->create();

        $emp = Employee::factory()
            ->inOrganization($orgs['school'])
            ->withGrade(EntitlementGrade::TEACHER_SENIOR) // 45 days
            ->create(['birth_date' => now()->subYears(35)]);

        $service = app(LeaveBalanceService::class);
        $service->accrueAnnual($emp, now()->year);

        $regularBal = LeaveBalance::where('employee_id', $emp->id)
            ->where('leave_type_id', $regular->id)
            ->first();

        expect((float) $regularBal->entitled)->toBe(45.0); // from grade, not default
    });
});

// =============================================================================
// GROUP 2: Validation Rules
// =============================================================================

describe('Validation Rules', function () {

    describe('WorkingDaysRule', function () {

        it('counts all calendar days inclusive (weekends included)', function () {
            $rule = new WorkingDaysRule();
            $rule->setData([
                'start_date' => '2026-06-01',
                'end_date'   => '2026-06-07',
            ]);
            $rule->validate('days', 7, fn($msg) => null);

            expect($rule->calculatedDays)->toBe(7);
        });

        it('does not subtract holidays from calendar-day count', function () {
            Holiday::create(['date' => '2026-06-03', 'name' => 'إجازة رسمية']);

            $rule = new WorkingDaysRule();
            $rule->setData([
                'start_date' => '2026-06-01',
                'end_date'   => '2026-06-07',
            ]);
            $rule->validate('days', 7, fn($msg) => null);

            expect($rule->calculatedDays)->toBe(7);
        });

        it('returns correct count for a single working day', function () {
            $rule = new WorkingDaysRule();
            $rule->setData([
                'start_date' => '2026-06-01', // Monday
                'end_date'   => '2026-06-01',
            ]);
            $rule->validate('days', 1, fn($msg) => null);

            expect($rule->calculatedDays)->toBe(1);
        });
    });

    describe('SufficientBalanceRule', function () {

        it('warns but does not fail when warn_only is true and balance insufficient', function () {
            config(['leave.warn_only_insufficient_balance' => true]);

            $orgs = createHierarchy();
            $lt   = LeaveType::factory()->regular()->create();
            $emp  = Employee::factory()->inOrganization($orgs['school'])->create();

            LeaveBalance::create([
                'employee_id'   => $emp->id,
                'leave_type_id' => $lt->id,
                'year'          => now()->year,
                'entitled'      => 5,
                'carried_over'  => 0,
                'used'          => 0,
            ]);

            $failed = false;
            $rule = new SufficientBalanceRule($emp, $lt, now()->year);
            $rule->validate('days', 10, function ($msg) use (&$failed) {
                $failed = true;
            });

            expect($failed)->toBeFalse();
            expect($rule->warning)->not->toBeNull();
            expect($rule->warning)->toContain('5.0');
        });

        it('fails when warn_only is false and balance insufficient', function () {
            config(['leave.warn_only_insufficient_balance' => false]);

            $orgs = createHierarchy();
            $lt   = LeaveType::factory()->regular()->create();
            $emp  = Employee::factory()->inOrganization($orgs['school'])->create();

            LeaveBalance::create([
                'employee_id'   => $emp->id,
                'leave_type_id' => $lt->id,
                'year'          => now()->year,
                'entitled'      => 5,
                'carried_over'  => 0,
                'used'          => 0,
            ]);

            $failed = false;
            $rule = new SufficientBalanceRule($emp, $lt, now()->year);
            $rule->validate('days', 10, function ($msg) use (&$failed) {
                $failed = true;
            });

            expect($failed)->toBeTrue();

            // Reset
            config(['leave.warn_only_insufficient_balance' => true]);
        });

        it('passes when balance is sufficient', function () {
            $orgs = createHierarchy();
            $lt   = LeaveType::factory()->regular()->create();
            $emp  = Employee::factory()->inOrganization($orgs['school'])->create();

            LeaveBalance::factory()->forEmployee($emp)->forLeaveType($lt)->create([
                'entitled' => 30,
                'used'     => 5,
            ]);

            $failed = false;
            $rule = new SufficientBalanceRule($emp, $lt, now()->year);
            $rule->validate('days', 10, function ($msg) use (&$failed) {
                $failed = true;
            });

            expect($failed)->toBeFalse();
            expect($rule->warning)->toBeNull();
        });
    });

    describe('NoOverlapRule', function () {

        it('fails when overlapping submitted request exists', function () {
            $orgs = createHierarchy();
            $lt   = LeaveType::factory()->regular()->create();
            $emp  = Employee::factory()->inOrganization($orgs['school'])->create();
            $user = User::factory()->inOrganization($orgs['school'])->create();

            LeaveRequest::factory()->forEmployee($emp)->submitted()->create([
                'leave_type_id' => $lt->id,
                'start_date'    => '2026-07-01',
                'end_date'      => '2026-07-10',
                'days'          => 8,
                'created_by'    => $user->id,
            ]);

            $failed = false;
            $rule = new NoOverlapRule($emp->id);
            $rule->setData(['start_date' => '2026-07-05', 'end_date' => '2026-07-15']);
            $rule->validate('days', 8, function ($msg) use (&$failed) {
                $failed = true;
            });

            expect($failed)->toBeTrue();
        });

        it('passes when only rejected requests overlap', function () {
            $orgs = createHierarchy();
            $lt   = LeaveType::factory()->regular()->create();
            $emp  = Employee::factory()->inOrganization($orgs['school'])->create();
            $user = User::factory()->inOrganization($orgs['school'])->create();

            LeaveRequest::factory()->forEmployee($emp)->rejected()->create([
                'leave_type_id' => $lt->id,
                'start_date'    => '2026-08-01',
                'end_date'      => '2026-08-10',
                'days'          => 8,
                'created_by'    => $user->id,
            ]);

            $failed = false;
            $rule = new NoOverlapRule($emp->id);
            $rule->setData(['start_date' => '2026-08-05', 'end_date' => '2026-08-08']);
            $rule->validate('days', 3, function ($msg) use (&$failed) {
                $failed = true;
            });

            expect($failed)->toBeFalse();
        });
    });

    describe('MaxDaysPerRequestRule', function () {

        it('fails when days exceed max_days_per_request', function () {
            $lt = LeaveType::factory()->casual()->create(); // max = 7

            $failed = false;
            $rule = new MaxDaysPerRequestRule($lt);
            $rule->validate('days', 10, function ($msg) use (&$failed) {
                $failed = true;
            });

            expect($failed)->toBeTrue();
        });

        it('passes when max_days_per_request is null', function () {
            $lt = LeaveType::factory()->regular()->create(); // max = null

            $failed = false;
            $rule = new MaxDaysPerRequestRule($lt);
            $rule->validate('days', 100, function ($msg) use (&$failed) {
                $failed = true;
            });

            expect($failed)->toBeFalse();
        });
    });

    describe('CasualBeforeRegularRule', function () {

        it('warns when 1-day regular leave requested with casual balance available', function () {
            config(['leave.casual_before_regular_warning' => true]);

            $orgs = createHierarchy();
            $regular = LeaveType::factory()->regular()->create();
            $casual  = LeaveType::factory()->casual()->create();
            $emp = Employee::factory()->inOrganization($orgs['school'])->create();

            LeaveBalance::create([
                'employee_id'   => $emp->id,
                'leave_type_id' => $casual->id,
                'year'          => now()->year,
                'entitled'      => 7,
                'carried_over'  => 0,
                'used'          => 0,
            ]);

            $rule = new CasualBeforeRegularRule($emp, $regular, now()->year);
            $rule->validate('days', 1, fn($msg) => null);

            expect($rule->warning)->not->toBeNull();
            expect($rule->warning)->toContain('عارضة');
        });

        it('does not warn for 3-day regular leave', function () {
            $orgs = createHierarchy();
            $regular = LeaveType::factory()->regular()->create();
            $emp = Employee::factory()->inOrganization($orgs['school'])->create();

            $rule = new CasualBeforeRegularRule($emp, $regular, now()->year);
            $rule->validate('days', 3, fn($msg) => null);

            expect($rule->warning)->toBeNull();
        });

        it('does not warn when casual balance is zero', function () {
            $orgs = createHierarchy();
            $regular = LeaveType::factory()->regular()->create();
            $casual  = LeaveType::factory()->casual()->create();
            $emp = Employee::factory()->inOrganization($orgs['school'])->create();

            LeaveBalance::create([
                'employee_id'   => $emp->id,
                'leave_type_id' => $casual->id,
                'year'          => now()->year,
                'entitled'      => 7,
                'carried_over'  => 0,
                'used'          => 7, // exhausted
            ]);

            $rule = new CasualBeforeRegularRule($emp, $regular, now()->year);
            $rule->validate('days', 1, fn($msg) => null);

            expect($rule->warning)->toBeNull();
        });
    });
});

// =============================================================================
// GROUP 3: LeaveRequestService — Lifecycle
// =============================================================================

describe('LeaveRequestService lifecycle', function () {

    it('create returns CreateLeaveRequestResult with DRAFT status', function () {
        $ctx = setupPhase2();

        $service = app(LeaveRequestService::class);
        $result  = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => now()->addDays(4)->toDateString(),
            'end_date'      => now()->addDays(6)->toDateString(),
            'days'          => 3,
        ], $ctx['user']);

        expect($result->request)->toBeInstanceOf(\App\Models\LeaveRequest::class);
        expect($result->request->status)->toBe(LeaveStatus::DRAFT);
        expect($result->request->employee_id)->toBe($ctx['employee']->id);
    });

    it('create calculates working days automatically', function () {
        $ctx = setupPhase2();

        // Week Mon–Sun: 5 working days (excludes Fri+Sat)
        $service = app(LeaveRequestService::class);
        $result  = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => now()->next('Monday')->toDateString(),
            'end_date'      => now()->next('Monday')->addDays(6)->toDateString(),
            'days'          => 99,            // wrong — should be corrected to 5
        ], $ctx['user']);

        expect((float) $result->request->days)->toBe(7.0);
    });

    it('submit builds workflow steps from WorkflowConfiguration', function () {
        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result  = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => now()->addDays(8)->toDateString(),
            'end_date'      => now()->addDays(10)->toDateString(),
            'days'          => 3,
        ], $ctx['user']);

        $submitted = $service->submit($result->request, $ctx['user']);

        expect($submitted->status)->toBe(LeaveStatus::SUBMITTED);
        expect($submitted->steps()->count())->toBe(3);
        expect($submitted->current_stage)->toBe('direct_manager');
    });

    it('submit snapshots balance onto the request', function () {
        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result    = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => now()->addDays(3)->toDateString(),
            'end_date'      => now()->addDays(5)->toDateString(),
            'days'          => 3,
        ], $ctx['user']);

        $submitted = $service->submit($result->request, $ctx['user']);

        expect($submitted->balance_entitled)->not->toBeNull();
        expect($submitted->balance_remaining)->not->toBeNull();
        expect((float) $submitted->balance_entitled)->toBe(30.0);
        expect((float) $submitted->balance_remaining)->toBe(30.0);
    });

    it('submit throws LeaveRequestException when request is already submitted', function () {
        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result    = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => '2026-11-01',
            'end_date'      => '2026-11-03',
            'days'          => 3,
        ], $ctx['user']);

        $submitted = $service->submit($result->request, $ctx['user']);

        expect(fn() => $service->submit($submitted, $ctx['user']))
            ->toThrow(LeaveRequestException::class);
    });

    it('approve at first stage (ANY rule) advances to IN_REVIEW', function () {
        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result    = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => '2026-11-10',
            'end_date'      => '2026-11-12',
            'days'          => 3,
        ], $ctx['user']);

        $submitted = $service->submit($result->request, $ctx['user']);

        $firstStep = $submitted->steps()->where('stage', 'direct_manager')->first();

        $updated = $service->approve($submitted, $firstStep, $ctx['user']);

        expect($updated->status)->toBe(LeaveStatus::IN_REVIEW);
        expect($updated->current_stage)->toBe('leaves_officer');
    });

    it('full happy path: create → submit → approve all 3 stages → APPROVED + balance deducted', function () {
        Notification::fake();

        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => '2026-12-01',
            'end_date'      => '2026-12-03',
            'days'          => 3,
        ], $ctx['user']);

        $req = $service->submit($result->request, $ctx['user']);

        // Stage 1: direct_manager
        $step1 = $req->steps()->where('stage', 'direct_manager')->first();
        $req   = $service->approve($req, $step1, $ctx['user']);

        // Stage 2: leaves_officer
        $step2 = $req->steps()->where('stage', 'leaves_officer')->first();
        $req   = $service->approve($req, $step2, $ctx['user']);

        // Stage 3: admin_manager (final)
        $step3 = $req->steps()->where('stage', 'admin_manager')->first();
        $req   = $service->approve($req, $step3, $ctx['user']);

        expect($req->status)->toBe(LeaveStatus::APPROVED);

        $balance = LeaveBalance::where('employee_id', $ctx['employee']->id)
            ->where('leave_type_id', $ctx['regular']->id)
            ->first();

        expect((float) $balance->used)->toBe((float) $req->days);
    });

    it('reject at any stage sets status to REJECTED and requires reason', function () {
        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => now()->addDays(10)->toDateString(),
            'end_date'      => now()->addDays(12)->toDateString(),
            'days'          => 3,
        ], $ctx['user']);

        $req    = $service->submit($result->request, $ctx['user']);
        $step1  = $req->steps()->where('stage', 'direct_manager')->first();

        $rejected = $service->reject($req, $step1, $ctx['user'], 'يوجد ضغط عمل في هذه الفترة');

        expect($rejected->status)->toBe(LeaveStatus::REJECTED);
        expect($rejected->rejection_reason)->toBe('يوجد ضغط عمل في هذه الفترة');

        // All remaining steps should be SKIPPED
        $pending = $rejected->steps()->where('status', StepStatus::PENDING)->count();
        expect($pending)->toBe(0);
    });

    it('reject throws exception when reason is empty', function () {
        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => now()->addDays(15)->toDateString(),
            'end_date'      => now()->addDays(17)->toDateString(),
            'days'          => 3,
        ], $ctx['user']);

        $req   = $service->submit($result->request, $ctx['user']);
        $step1 = $req->steps()->where('stage', 'direct_manager')->first();

        expect(fn() => $service->reject($req, $step1, $ctx['user'], '   '))
            ->toThrow(LeaveRequestException::class);
    });

    it('returnRequest sets status to RETURNED and marks remaining steps SKIPPED', function () {
        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => now()->addDays(20)->toDateString(),
            'end_date'      => now()->addDays(22)->toDateString(),
            'days'          => 3,
        ], $ctx['user']);

        $req    = $service->submit($result->request, $ctx['user']);
        $step1  = $req->steps()->where('stage', 'direct_manager')->first();

        $returned = $service->returnRequest($req, $step1, $ctx['user'], 'يرجى إرفاق المستندات');

        expect($returned->status)->toBe(LeaveStatus::RETURNED);
        expect($returned->current_stage)->toBeNull();

        $pending = $returned->steps()->where('status', StepStatus::PENDING)->count();
        expect($pending)->toBe(0);
    });

    it('returned request can be resubmitted', function () {
        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result  = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => '2026-10-15',
            'end_date'      => '2026-10-17',
            'days'          => 3,
        ], $ctx['user']);

        $req     = $service->submit($result->request, $ctx['user']);
        $step1   = $req->steps()->where('stage', 'direct_manager')->first();
        $returned = $service->returnRequest($req, $step1, $ctx['user'], 'ناقص مستند');

        // Re-submit
        $resubmitted = $service->submit($returned, $ctx['user']);

        expect($resubmitted->status)->toBe(LeaveStatus::SUBMITTED);
        expect($resubmitted->steps()->count())->toBe(3);
    });

    it('cancel on DRAFT sets status to CANCELLED without touching balance', function () {
        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => '2026-11-05',
            'end_date'      => '2026-11-07',
            'days'          => 3,
        ], $ctx['user']);

        $cancelled = $service->cancel($result->request, $ctx['user']);

        expect($cancelled->status)->toBe(LeaveStatus::CANCELLED);

        // Balance should not have changed
        $balance = LeaveBalance::where('employee_id', $ctx['employee']->id)
            ->where('leave_type_id', $ctx['regular']->id)
            ->first();

        expect($balance)->not->toBeNull();
        expect((float) $balance->used)->toBe(0.0);
    });

    it('cancel on APPROVED refunds the balance', function () {
        Notification::fake();

        $ctx = setupPhase2();
        $service = app(LeaveRequestService::class);

        $result = $service->create([
            'employee_id'   => $ctx['employee']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'             => 'ظرف عائلي للاختبار',
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => '2026-11-17',
            'end_date'      => '2026-11-19',
            'days'          => 3,
        ], $ctx['user']);

        $req = $service->submit($result->request, $ctx['user']);

        // Approve all 3 stages
        foreach (['direct_manager', 'leaves_officer', 'admin_manager'] as $stage) {
            $step = $req->steps()->where('stage', $stage)->first();
            $req  = $service->approve($req, $step, $ctx['user']);
        }

        expect($req->status)->toBe(LeaveStatus::APPROVED);

        $balanceBefore = (float) LeaveBalance::where('employee_id', $ctx['employee']->id)
            ->where('leave_type_id', $ctx['regular']->id)
            ->first()->used;

        // Now cancel
        $cancelled = $service->cancel($req, $ctx['user']);

        expect($cancelled->status)->toBe(LeaveStatus::CANCELLED);

        $balanceAfter = (float) LeaveBalance::where('employee_id', $ctx['employee']->id)
            ->where('leave_type_id', $ctx['regular']->id)
            ->first()->used;

        expect($balanceAfter)->toBe($balanceBefore - (float) $req->days);
    });
});

// =============================================================================
// GROUP 4: Notifications
// =============================================================================

describe('Notifications', function () {

    it('toDatabase returns expected array shape', function () {
        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()->inOrganization($orgs['school'])->create();
        $user = User::factory()->inOrganization($orgs['school'])->create();

        $request = LeaveRequest::factory()->forEmployee($emp)->submitted()->create([
            'leave_type_id' => $lt->id,
            'created_by'    => $user->id,
        ]);

        $notification = new LeaveRequestNotification($request, 'submitted', 'طلب جديد');
        $data = $notification->toDatabase($user);

        expect($data)->toHaveKeys([
            'leave_request_id',
            'number',
            'event',
            'message',
            'status',
        ]);
        expect($data['event'])->toBe('submitted');
        expect($data['leave_request_id'])->toBe($request->id);
    });

    it('DatabaseChannel stores a database notification', function () {
        Notification::fake();

        $orgs = createHierarchy();
        $lt   = LeaveType::factory()->regular()->create();
        $emp  = Employee::factory()->inOrganization($orgs['school'])->create();
        $user = User::factory()->inOrganization($orgs['school'])->create();

        $request = LeaveRequest::factory()->forEmployee($emp)->submitted()->create([
            'leave_type_id' => $lt->id,
            'created_by'    => $user->id,
        ]);

        $notification = new LeaveRequestNotification($request, 'approved', 'تمت الموافقة');

        $channel = new \App\Notifications\Channels\DatabaseChannel();
        $channel->send($user, $notification);

        Notification::assertSentTo($user, LeaveRequestNotification::class);
    });
});
