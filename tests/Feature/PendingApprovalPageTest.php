<?php

use App\Enums\EntitlementGrade;
use App\Enums\LeaveStatus;
use App\Enums\StepStatus;
use App\Exceptions\LeaveRequestException;
use App\Filament\Pages\PendingApprovalPage;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkflowConfiguration;
use App\Services\LeaveRequestService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// =============================================================================
// Helpers
// =============================================================================

/**
 * Create all permissions needed by the panel and approval actions.
 */
function ensureAllPermissions(): void
{
    foreach ([
        'approve_leave_request',
        'reject_leave_request',
        'return_leave_request',
        'manage_organization',
        'view_leave_requests',
    ] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }
}

/**
 * Assign a named role (with approval permissions) to a user scoped to an org.
 */
function assignApproverRole(User $user, string $roleName, int $orgId): void
{
    ensureAllPermissions();
    setPermissionsTeamId($orgId);
    $role = Role::firstOrCreate([
        'name'            => $roleName,
        'guard_name'      => 'web',
        'organization_id' => $orgId,
    ]);
    $role->givePermissionTo('approve_leave_request');
    $role->givePermissionTo('reject_leave_request');
    $role->givePermissionTo('return_leave_request');
    $user->assignRole($role);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
}

/**
 * Assign panel-access role (manage_organization) without approve permission.
 */
function assignPanelAccessRole(User $user, int $orgId): void
{
    ensureAllPermissions();
    setPermissionsTeamId($orgId);
    $role = Role::firstOrCreate([
        'name'            => 'كاتب الإدارة',
        'guard_name'      => 'web',
        'organization_id' => $orgId,
    ]);
    $role->givePermissionTo('manage_organization');
    $user->assignRole($role);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
}

/**
 * Create a submitted leave request at the given stage with a pending workflow step.
 */
function makeSubmittedRequest(Employee $employee, LeaveType $lt, string $stage, User $createdBy): LeaveRequest
{
    $req = LeaveRequest::create([
        'number'          => 'LR-' . uniqid(),
        'employee_id'     => $employee->id,
        'organization_id' => $employee->organization_id,
        'leave_type_id'   => $lt->id,
        'start_date'      => '2026-09-01',
        'end_date'        => '2026-09-05',
        'days'            => 5,
        'status'          => LeaveStatus::SUBMITTED,
        'current_stage'   => $stage,
        'created_by'      => $createdBy->id,
        'submitted_at'    => now()->subHour(),
    ]);

    LeaveRequestStep::create([
        'leave_request_id' => $req->id,
        'step_order'       => 1,
        'stage'            => $stage,
        'status'           => StepStatus::PENDING,
    ]);

    return $req;
}

// =============================================================================
// Test 1: 403 when user lacks approve_leave_request permission
// =============================================================================

it('returns 403 for a user without approve_leave_request permission', function () {
    $orgs = createHierarchy();

    // User gets panel access but no approve_leave_request permission
    $user = User::factory()->inOrganization($orgs['administration'])->create();
    assignPanelAccessRole($user, $orgs['administration']->id);

    setPermissionsTeamId($orgs['administration']->id);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->actingAs($user->fresh())
        ->get('/admin/pending-approval-page')
        ->assertForbidden();
});

// =============================================================================
// Test 2: canAccess returns false without auth context
// =============================================================================

it('canAccess returns false when no auth user is set', function () {
    // No actingAs — Auth::user() returns null
    expect(PendingApprovalPage::canAccess())->toBeFalse();
});

// =============================================================================
// Test 3: buildPendingQuery filters by stage — school_principal
// =============================================================================

it('buildPendingQuery returns school_principal requests for a principal user only', function () {
    $orgs = createHierarchy();
    $lt   = LeaveType::factory()->regular()->create();
    $emp  = Employee::factory()->inOrganization($orgs['school'])->create();

    $createdBy = User::factory()->inOrganization($orgs['school'])->create();

    // Request at school_principal stage — should appear
    $principalRequest = makeSubmittedRequest($emp, $lt, 'school_principal', $createdBy);

    // Request at leaves_officer stage — should NOT appear
    $officerRequest = makeSubmittedRequest($emp, $lt, 'leaves_officer', $createdBy);

    $principal = User::factory()->inOrganization($orgs['school'])->create();
    assignApproverRole($principal, 'مدير مدرسة', $orgs['school']->id);

    setPermissionsTeamId($orgs['school']->id);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $freshPrincipal = $principal->fresh();
    $freshPrincipal->setOrganizationTeam();

    $ids = PendingApprovalPage::buildPendingQuery($freshPrincipal)->pluck('id')->toArray();

    expect($ids)->toContain($principalRequest->id);
    expect($ids)->not->toContain($officerRequest->id);
});

// =============================================================================
// Test 4: buildPendingQuery filters by stage — leaves_officer
// =============================================================================

it('buildPendingQuery returns leaves_officer requests for a leaves-officer user', function () {
    $orgs = createHierarchy();
    $lt   = LeaveType::factory()->regular()->create();
    $emp  = Employee::factory()->inOrganization($orgs['school'])->create();

    $createdBy = User::factory()->inOrganization($orgs['school'])->create();

    // leaves_officer stage — should appear
    $officerRequest = makeSubmittedRequest($emp, $lt, 'leaves_officer', $createdBy);

    // school_principal stage — should NOT appear
    $principalRequest = makeSubmittedRequest($emp, $lt, 'school_principal', $createdBy);

    $officer = User::factory()->inOrganization($orgs['administration'])->create();
    assignApproverRole($officer, 'مسؤول الإجازات', $orgs['administration']->id);

    setPermissionsTeamId($orgs['administration']->id);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $fresh = $officer->fresh();
    $fresh->setOrganizationTeam();

    $ids = PendingApprovalPage::buildPendingQuery($fresh)->pluck('id')->toArray();

    expect($ids)->toContain($officerRequest->id);
    expect($ids)->not->toContain($principalRequest->id);
});

// =============================================================================
// Test 5: buildPendingQuery returns empty for user with no qualifying stage
// =============================================================================

it('buildPendingQuery returns empty result for user with no qualifying role', function () {
    $orgs      = createHierarchy();
    $lt        = LeaveType::factory()->regular()->create();
    $emp       = Employee::factory()->inOrganization($orgs['school'])->create();
    $createdBy = User::factory()->inOrganization($orgs['school'])->create();

    makeSubmittedRequest($emp, $lt, 'school_principal', $createdBy);

    ensureAllPermissions();

    // A school user with approve permission but not the مدير مدرسة role
    $user = User::factory()->inOrganization($orgs['school'])->create();
    setPermissionsTeamId($orgs['school']->id);
    $role = Role::firstOrCreate([
        'name'            => 'موظف عادي',
        'guard_name'      => 'web',
        'organization_id' => $orgs['school']->id,
    ]);
    $role->givePermissionTo('approve_leave_request');
    $user->assignRole($role);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $fresh = $user->fresh();
    $fresh->setOrganizationTeam();

    expect(PendingApprovalPage::buildPendingQuery($fresh)->count())->toBe(0);
});

// =============================================================================
// Test 6: Service approve via direct DB request
// =============================================================================

it('LeaveRequestService approve advances request stage', function () {
    $orgs      = createHierarchy();
    $lt        = LeaveType::factory()->regular()->create();
    $emp       = Employee::factory()->inOrganization($orgs['school'])->create();
    $createdBy = User::factory()->inOrganization($orgs['school'])->create();
    $approver  = User::factory()->inOrganization($orgs['school'])->create();

    // Set up workflow config so the service knows the next stage
    WorkflowConfiguration::factory()->directManagerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->leavesOfficerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->adminManagerStage($orgs['school'])->create();

    $req  = makeSubmittedRequest($emp, $lt, 'direct_manager', $createdBy);
    $step = $req->steps()->where('stage', 'direct_manager')->first();

    $updated = app(LeaveRequestService::class)->approve($req, $step, $approver, 'موافق');

    expect($updated->status)->toBe(LeaveStatus::IN_REVIEW);
    expect($updated->current_stage)->toBe('leaves_officer');
});

// =============================================================================
// Test 7: Service reject requires a non-empty reason
// =============================================================================

it('LeaveRequestService reject sets REJECTED status with a reason', function () {
    $orgs      = createHierarchy();
    $lt        = LeaveType::factory()->regular()->create();
    $emp       = Employee::factory()->inOrganization($orgs['school'])->create();
    $createdBy = User::factory()->inOrganization($orgs['school'])->create();
    $approver  = User::factory()->inOrganization($orgs['school'])->create();

    WorkflowConfiguration::factory()->directManagerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->leavesOfficerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->adminManagerStage($orgs['school'])->create();

    $req      = makeSubmittedRequest($emp, $lt, 'direct_manager', $createdBy);
    $step     = $req->steps()->where('stage', 'direct_manager')->first();
    $rejected = app(LeaveRequestService::class)->reject($req, $step, $approver, 'يوجد ضغط عمل في هذه الفترة');

    expect($rejected->status)->toBe(LeaveStatus::REJECTED);
    expect($rejected->rejection_reason)->toBe('يوجد ضغط عمل في هذه الفترة');
});

it('LeaveRequestService reject throws exception when reason is empty', function () {
    $orgs      = createHierarchy();
    $lt        = LeaveType::factory()->regular()->create();
    $emp       = Employee::factory()->inOrganization($orgs['school'])->create();
    $createdBy = User::factory()->inOrganization($orgs['school'])->create();
    $approver  = User::factory()->inOrganization($orgs['school'])->create();

    WorkflowConfiguration::factory()->directManagerStage($orgs['school'])->create();

    $req  = makeSubmittedRequest($emp, $lt, 'direct_manager', $createdBy);
    $step = $req->steps()->where('stage', 'direct_manager')->first();

    expect(fn () => app(LeaveRequestService::class)->reject($req, $step, $approver, '   '))
        ->toThrow(LeaveRequestException::class);
});

// =============================================================================
// Test 8: Service returnRequest requires a note
// =============================================================================

it('LeaveRequestService returnRequest sets RETURNED status', function () {
    $orgs      = createHierarchy();
    $lt        = LeaveType::factory()->regular()->create();
    $emp       = Employee::factory()->inOrganization($orgs['school'])->create();
    $createdBy = User::factory()->inOrganization($orgs['school'])->create();
    $approver  = User::factory()->inOrganization($orgs['school'])->create();

    WorkflowConfiguration::factory()->directManagerStage($orgs['school'])->create();

    $req      = makeSubmittedRequest($emp, $lt, 'direct_manager', $createdBy);
    $step     = $req->steps()->where('stage', 'direct_manager')->first();
    $returned = app(LeaveRequestService::class)->returnRequest($req, $step, $approver, 'يرجى إرفاق المستندات');

    expect($returned->status)->toBe(LeaveStatus::RETURNED);
});

it('LeaveRequestService returnRequest throws for empty note', function () {
    $orgs      = createHierarchy();
    $lt        = LeaveType::factory()->regular()->create();
    $emp       = Employee::factory()->inOrganization($orgs['school'])->create();
    $createdBy = User::factory()->inOrganization($orgs['school'])->create();
    $approver  = User::factory()->inOrganization($orgs['school'])->create();

    WorkflowConfiguration::factory()->directManagerStage($orgs['school'])->create();

    $req      = makeSubmittedRequest($emp, $lt, 'direct_manager', $createdBy);
    $step     = $req->steps()->where('stage', 'direct_manager')->first();
    expect(fn () => app(LeaveRequestService::class)->returnRequest($req, $step, $approver, ''))
        ->toThrow(LeaveRequestException::class);
});
