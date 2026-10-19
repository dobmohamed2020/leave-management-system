<?php

use App\Enums\LeaveStatus;
use App\Exports\LeaveRequestsExport;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Services\LeaveRequestPdfService;
use Illuminate\Support\Facades\Notification;

// =============================================================================
// Test 1 — PDF generation
// =============================================================================

it('generates a non-empty PDF binary string for an approved leave request', function () {
    $orgs = createHierarchy();
    $org = $orgs['school'];

    $employee = Employee::factory()->inOrganization($org)->create();
    $leaveType = LeaveType::factory()->regular()->create();
    $user = User::factory()->inOrganization($org)->create();

    $request = LeaveRequest::create([
        'number' => 'LR-PDF-001',
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-07-01',
        'end_date' => '2025-07-05',
        'days' => 5,
        'status' => LeaveStatus::APPROVED,
        'created_by' => $user->id,
        'decided_at' => now(),
    ]);

    $request->load(['employee', 'leaveType', 'organization', 'substituteEmployee']);

    $pdfContent = app(LeaveRequestPdfService::class)->generate($request);

    expect($pdfContent)->not->toBeEmpty();
    expect(substr($pdfContent, 0, 4))->toBe('%PDF');
});

// =============================================================================
// Test 2 — Public verification page returns 200 with employee name
// =============================================================================

it('returns 200 with employee name on GET /verify/{number}', function () {
    $orgs = createHierarchy();
    $org = $orgs['school'];

    $employee = Employee::factory()->inOrganization($org)->create(['full_name' => 'موظف تجريبي للتحقق']);
    $leaveType = LeaveType::factory()->regular()->create();
    $user = User::factory()->inOrganization($org)->create();

    $leave = LeaveRequest::create([
        'number' => 'LR-VERIFY-001',
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-08-01',
        'end_date' => '2025-08-05',
        'days' => 5,
        'status' => LeaveStatus::APPROVED,
        'created_by' => $user->id,
    ]);

    $this->get(route('leave.verify', ['token' => $leave->verification_token]))
        ->assertOk()
        ->assertSee('موظف تجريبي للتحقق');
});

// =============================================================================
// Test 3 — Public verification page returns 404 for unknown number
// =============================================================================

it('returns 404 for an unknown verification token', function () {
    $this->get(route('leave.verify', ['token' => '00000000-0000-4000-8000-000000000000']))
        ->assertNotFound();
});

it('returns 404 for legacy verify when leave is not approved', function () {
    $orgs = createHierarchy();
    $employee = Employee::factory()->inOrganization($orgs['school'])->create();
    $leaveType = LeaveType::factory()->regular()->create();
    $user = User::factory()->inOrganization($orgs['school'])->create();

    $leave = LeaveRequest::create([
        'number' => 'LR-VERIFY-DRAFT',
        'employee_id' => $employee->id,
        'organization_id' => $orgs['school']->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-08-01',
        'end_date' => '2025-08-05',
        'days' => 5,
        'status' => LeaveStatus::DRAFT,
        'created_by' => $user->id,
    ]);

    $this->get(route('leave.verify.legacy', ['number' => $leave->number]))->assertNotFound();
});

// =============================================================================
// Test 4 — Excel export query filters by status
// =============================================================================

it('export query returns only rows matching the status filter', function () {
    $orgs = createHierarchy();
    $org = $orgs['school'];

    $leaveType = LeaveType::factory()->regular()->create();
    $user = User::factory()->inOrganization($org)->create();

    // 2 approved
    LeaveRequest::factory()
        ->forEmployee(Employee::factory()->inOrganization($org)->create())
        ->approved()
        ->count(2)
        ->create([
            'organization_id' => $org->id,
            'leave_type_id' => $leaveType->id,
            'created_by' => $user->id,
        ]);

    // 1 rejected
    LeaveRequest::factory()
        ->forEmployee(Employee::factory()->inOrganization($org)->create())
        ->rejected()
        ->create([
            'organization_id' => $org->id,
            'leave_type_id' => $leaveType->id,
            'created_by' => $user->id,
        ]);

    $export = new LeaveRequestsExport(['status' => 'approved']);

    expect($export->query()->count())->toBe(2);
});

// =============================================================================
// Test 5 — Overdue command sends notifications to created_by user
// =============================================================================

it('leave:notify-overdue sends notifications to created_by users', function () {
    Notification::fake();

    $orgs = createHierarchy();
    $org = $orgs['school'];

    $user = User::factory()->inOrganization($org)->create();
    $employee = Employee::factory()->inOrganization($org)->create();
    $leaveType = LeaveType::factory()->regular()->create();

    // A submitted request that is 2 days old (overdue by 1+ day)
    LeaveRequest::create([
        'number' => 'LR-OVERDUE-001',
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-09-01',
        'end_date' => '2025-09-05',
        'days' => 5,
        'status' => LeaveStatus::SUBMITTED,
        'created_by' => $user->id,
        'submitted_at' => now()->subDays(2),
    ]);

    $this->artisan('leave:notify-overdue', ['--days' => 1])
        ->assertExitCode(0);

    Notification::assertSentTo(
        $user,
        LeaveRequestNotification::class,
        fn ($n) => $n->event === 'overdue'
    );
});
