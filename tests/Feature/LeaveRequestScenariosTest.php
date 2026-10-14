<?php

/**
 * سيناريوهات شاملة لطلب الإجازة: إنشاء، تقديم، اعتماد كل مرحلة، رفض، إعادة، إلغاء، حذف، API.
 */

use App\Enums\EntitlementGrade;
use App\Enums\LeaveStatus;
use App\Enums\StepStatus;
use App\Exceptions\LeaveRequestException;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkflowConfiguration;
use App\Services\FiscalYearService;
use App\Services\LeaveRequestService;
use Database\Seeders\EntitlementGradeSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function setupLeaveScenarios(): array
{
    test()->seed(EntitlementGradeSeeder::class);

    $orgs = createHierarchy();
    $regular = LeaveType::factory()->regular()->create();
    $casual = LeaveType::factory()->casual()->create();
    $fiscal = app(FiscalYearService::class)->currentFiscalYear();

    $employee = Employee::factory()
        ->inOrganization($orgs['school'])
        ->withGrade(EntitlementGrade::TEACHER_FIRST)
        ->create(['birth_date' => now()->subYears(35)]);

    $substitute = Employee::factory()
        ->inOrganization($orgs['school'])
        ->create(['full_name' => 'موظف بديل']);

    $schoolStaff = User::factory()->inOrganization($orgs['school'])->create();
    grantApiSchoolEmployeePermissions($schoolStaff, $orgs['school']);

    $schoolManager = User::factory()->inOrganization($orgs['school'])->create();
    assignOrgRole($schoolManager, $orgs['school'], 'مدير مدرسة', [
        'create_leave_request', 'submit_leave_request', 'approve_leave_request',
        'return_leave_request', 'view_leave_requests', 'cancel_leave_request',
    ]);

    $leavesOfficer = User::factory()->inOrganization($orgs['administration'])->create();
    assignOrgRole($leavesOfficer, $orgs['administration'], 'مسؤول الإجازات', [
        'approve_leave_request', 'reject_leave_request', 'return_leave_request', 'view_leave_requests',
    ]);

    $adminManager = User::factory()->inOrganization($orgs['administration'])->create();
    assignOrgRole($adminManager, $orgs['administration'], 'مدير الإدارة', [
        'approve_leave_request', 'reject_leave_request', 'view_leave_requests',
    ]);

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $regular->id,
        'year' => $fiscal,
        'entitled' => 30,
        'carried_over' => 10,
        'used' => 0,
        'used_carried' => 0,
    ]);

    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $casual->id,
        'year' => $fiscal,
        'entitled' => 7,
        'carried_over' => 0,
        'used' => 0,
    ]);

    WorkflowConfiguration::factory()->directManagerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->leavesOfficerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->adminManagerStage($orgs['school'])->create();

    return compact(
        'orgs', 'regular', 'casual', 'fiscal', 'employee', 'substitute',
        'schoolStaff', 'schoolManager', 'leavesOfficer', 'adminManager'
    );
}

function scenarioLeavePayload(array $ctx, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $ctx['employee']->id,
        'substitute_employee_id' => $ctx['substitute']->id,
        'reason' => 'سيناريو اختبار',
        'leave_type_id' => $ctx['regular']->id,
        'start_date' => now()->addDays(14)->toDateString(),
        'end_date' => now()->addDays(16)->toDateString(),
        'days' => 3,
    ], $overrides);
}

function scenarioApproveAllStages(LeaveRequest $req, array $ctx): LeaveRequest
{
    $service = app(LeaveRequestService::class);

    $step1 = $req->steps()->where('stage', 'direct_manager')->first();
    $req = $service->approve($req, $step1, $ctx['schoolManager']);

    $step2 = $req->steps()->where('stage', 'leaves_officer')->first();
    $req = $service->approve($req, $step2, $ctx['leavesOfficer']);

    $step3 = $req->steps()->where('stage', 'admin_manager')->first();

    return $service->approve($req, $step3, $ctx['adminManager']);
}

// -------------------------------------------------------------------------
// إنشاء الطلب
// -------------------------------------------------------------------------

describe('سيناريوهات الإنشاء', function () {

    it('ينشئ إجازة اعتيادية كمسودة', function () {
        $ctx = setupLeaveScenarios();
        $result = app(LeaveRequestService::class)->create(
            scenarioLeavePayload($ctx),
            $ctx['schoolStaff']
        );

        expect($result->request->status)->toBe(LeaveStatus::DRAFT)
            ->and($result->request->days)->toBe(3);
    });

    it('ينشئ إجازة عارضة', function () {
        $ctx = setupLeaveScenarios();
        $result = app(LeaveRequestService::class)->create(
            scenarioLeavePayload($ctx, ['leave_type_id' => $ctx['casual']->id]),
            $ctx['schoolStaff']
        );

        expect($result->request->leave_type_id)->toBe($ctx['casual']->id);
    });

    it('ينشئ طلباً يخصم من الرصيد المُرحَّل فقط', function () {
        $ctx = setupLeaveScenarios();
        $result = app(LeaveRequestService::class)->create(
            scenarioLeavePayload($ctx, ['deduct_from_carried' => true]),
            $ctx['schoolStaff']
        );

        expect($result->request->deduct_from_carried)->toBeTrue();
    });

    it('يرفض تاريخ بداية في الماضي', function () {
        $ctx = setupLeaveScenarios();

        expect(fn () => app(LeaveRequestService::class)->create(
            scenarioLeavePayload($ctx, [
                'start_date' => now()->subDays(3)->toDateString(),
                'end_date' => now()->subDays(1)->toDateString(),
            ]),
            $ctx['schoolStaff']
        ))->toThrow(ValidationException::class);
    });

    it('يرفض التداخل مع طلب معتمد', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $payload = scenarioLeavePayload($ctx);

        LeaveRequest::factory()->forEmployee($ctx['employee'])->submitted()->create([
            'leave_type_id' => $ctx['regular']->id,
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'days' => 3,
            'created_by' => $ctx['schoolStaff']->id,
        ]);

        expect(fn () => $service->create($payload, $ctx['schoolStaff']))
            ->toThrow(ValidationException::class);
    });

    it('يرفض تجاوز الرصيد المتاح', function () {
        $ctx = setupLeaveScenarios();
        $start = now()->addDays(14);
        // الخدمة تعيد حساب الأيام من التواريخ (وليس الحقل days وحده)
        $end = $start->copy()->addDays(30);

        expect(fn () => app(LeaveRequestService::class)->create(
            scenarioLeavePayload($ctx, [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ]),
            $ctx['schoolStaff']
        ))->toThrow(ValidationException::class);
    });
});

// -------------------------------------------------------------------------
// التعديل
// -------------------------------------------------------------------------

describe('سيناريوهات التعديل', function () {

    it('يحدّث المسودة ويعيد حساب الأيام', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $draft = $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request;

        $updated = $service->update($draft, [
            'end_date' => now()->addDays(18)->toDateString(),
        ], $ctx['schoolStaff']);

        expect($updated->days)->toBeGreaterThan(3);
    });

    it('يمنع تعديل طلب معتمد', function () {
        Notification::fake();
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $draft = $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request;
        $req = $service->submit($draft, $ctx['schoolStaff']);
        $req = scenarioApproveAllStages($req, $ctx);

        expect(fn () => $service->update($req, ['reason' => 'تعديل'], $ctx['schoolStaff']))
            ->toThrow(ValidationException::class);
    });

    it('يسمح بتعديل الطلب المُعاد للتعديل', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $draft = $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request;
        $req = $service->submit($draft, $ctx['schoolStaff']);
        $step = $req->steps()->where('stage', 'direct_manager')->first();
        $returned = $service->returnRequest($req, $step, $ctx['schoolManager'], 'يرجى تصحيح السبب');

        $updated = $service->update($returned, ['reason' => 'سبب محدّث للاختبار'], $ctx['schoolStaff']);

        expect($updated->status)->toBe(LeaveStatus::RETURNED)
            ->and($updated->reason)->toBe('سبب محدّث للاختبار');
    });
});

// -------------------------------------------------------------------------
// التقديم والمراحل
// -------------------------------------------------------------------------

describe('سيناريوهات التقديم والاعتماد حسب المرحلة', function () {

    it('يقدّم المسودة وينشئ ثلاث مراحل', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $draft = $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request;
        $submitted = $service->submit($draft, $ctx['schoolStaff']);

        expect($submitted->status)->toBe(LeaveStatus::SUBMITTED)
            ->and($submitted->current_stage)->toBe('direct_manager')
            ->and($submitted->steps()->count())->toBe(3);
    });

    it('اعتماد المرحلة 1 (مدير مدرسة) ينقل إلى مسؤول الإجازات', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $req = $service->submit(
            $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request,
            $ctx['schoolStaff']
        );

        $step = $req->steps()->where('stage', 'direct_manager')->first();
        $req = $service->approve($req, $step, $ctx['schoolManager']);

        expect($req->status)->toBe(LeaveStatus::IN_REVIEW)
            ->and($req->current_stage)->toBe('leaves_officer');
    });

    it('اعتماد المرحلة 2 (مسؤول الإجازات) ينقل إلى مدير الإدارة', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $req = $service->submit(
            $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request,
            $ctx['schoolStaff']
        );
        $req = $service->approve(
            $req,
            $req->steps()->where('stage', 'direct_manager')->first(),
            $ctx['schoolManager']
        );

        $step = $req->steps()->where('stage', 'leaves_officer')->first();
        $req = $service->approve($req, $step, $ctx['leavesOfficer']);

        expect($req->current_stage)->toBe('admin_manager');
    });

    it('اعتماد المرحلة 3 يعتمد الطلب ويخصم من الرصيد السنوي', function () {
        Notification::fake();
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $req = $service->submit(
            $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request,
            $ctx['schoolStaff']
        );
        $req = scenarioApproveAllStages($req, $ctx);

        $balance = LeaveBalance::where('employee_id', $ctx['employee']->id)
            ->where('leave_type_id', $ctx['regular']->id)
            ->where('year', $ctx['fiscal'])
            ->first();

        expect($req->status)->toBe(LeaveStatus::APPROVED)
            ->and((float) $balance->used)->toBe(3.0)
            ->and((float) $balance->used_carried)->toBe(0.0);
    });

    it('اعتماد كامل مع خصم من الرصيد المُرحَّل فقط', function () {
        Notification::fake();
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $start = now()->addDays(25)->toDateString();
        $end = now()->addDays(26)->toDateString();
        $req = $service->submit(
            $service->create(scenarioLeavePayload($ctx, [
                'deduct_from_carried' => true,
                'start_date' => $start,
                'end_date' => $end,
                'days' => 2,
            ]), $ctx['schoolStaff'])->request,
            $ctx['schoolStaff']
        );
        $req = scenarioApproveAllStages($req, $ctx);

        $balance = LeaveBalance::where('employee_id', $ctx['employee']->id)
            ->where('leave_type_id', $ctx['regular']->id)
            ->where('year', $ctx['fiscal'])
            ->first();

        expect($req->status)->toBe(LeaveStatus::APPROVED)
            ->and((float) $balance->used_carried)->toBe(2.0)
            ->and((float) $balance->used)->toBe(0.0);
    });

    it('يمنع موظف المدرسة من اعتماد مرحلة مسؤول الإجازات', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $req = $service->submit(
            $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request,
            $ctx['schoolStaff']
        );
        $req = $service->approve(
            $req,
            $req->steps()->where('stage', 'direct_manager')->first(),
            $ctx['schoolManager']
        );

        $step = $req->steps()->where('stage', 'leaves_officer')->first();

        expect(fn () => $service->approve($req, $step, $ctx['schoolStaff']))
            ->toThrow(LeaveRequestException::class);
    });
});

// -------------------------------------------------------------------------
// الرفض والإعادة
// -------------------------------------------------------------------------

describe('سيناريوهات الرفض والإعادة', function () {

    it('رفض من مسؤول الإجازات مع سبب', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $req = $service->submit(
            $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request,
            $ctx['schoolStaff']
        );
        $req = $service->approve(
            $req,
            $req->steps()->where('stage', 'direct_manager')->first(),
            $ctx['schoolManager']
        );

        $step = $req->steps()->where('stage', 'leaves_officer')->first();
        $rejected = $service->reject($req, $step, $ctx['leavesOfficer'], 'ضغط عمل في الفترة المطلوبة');

        expect($rejected->status)->toBe(LeaveStatus::REJECTED)
            ->and($rejected->rejection_reason)->toContain('ضغط عمل');
    });

    it('إعادة للتعديل من مدير المدرسة في المرحلة الأولى', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $req = $service->submit(
            $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request,
            $ctx['schoolStaff']
        );

        $step = $req->steps()->where('stage', 'direct_manager')->first();
        $returned = $service->returnRequest($req, $step, $ctx['schoolManager'], 'يرجى إرفاق المستندات');

        expect($returned->status)->toBe(LeaveStatus::RETURNED)
            ->and($returned->steps()->where('status', StepStatus::SKIPPED)->count())->toBeGreaterThan(0);
    });

    it('بعد الإعادة يمكن إعادة التقديم', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $req = $service->submit(
            $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request,
            $ctx['schoolStaff']
        );
        $step = $req->steps()->where('stage', 'direct_manager')->first();
        $returned = $service->returnRequest($req, $step, $ctx['schoolManager'], 'يرجى تصحيح البيانات');
        $again = $service->submit($returned, $ctx['schoolStaff']);

        expect($again->status)->toBe(LeaveStatus::SUBMITTED)
            ->and($again->steps()->count())->toBe(3);
    });
});

// -------------------------------------------------------------------------
// الإلغاء والحذف
// -------------------------------------------------------------------------

describe('سيناريوهات الإلغاء والحذف', function () {

    it('إلغاء مسودة بدون خصم رصيد', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $draft = $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request;
        $cancelled = $service->cancel($draft, $ctx['schoolStaff']);

        $balance = LeaveBalance::where('employee_id', $ctx['employee']->id)
            ->where('leave_type_id', $ctx['regular']->id)
            ->first();

        expect($cancelled->status)->toBe(LeaveStatus::CANCELLED)
            ->and((float) $balance->used)->toBe(0.0);
    });

    it('إلغاء طلب معتمد يسترد الرصيد', function () {
        Notification::fake();
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $req = scenarioApproveAllStages(
            $service->submit(
                $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request,
                $ctx['schoolStaff']
            ),
            $ctx
        );

        $service->cancel($req, $ctx['schoolStaff']);

        $balance = LeaveBalance::where('employee_id', $ctx['employee']->id)
            ->where('leave_type_id', $ctx['regular']->id)
            ->first();

        expect((float) $balance->used)->toBe(0.0);
    });

    it('حذف ناعم للطلب ثم استعادته كمسودة', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $draft = $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request;

        $draft->delete();
        expect(LeaveRequest::withTrashed()->find($draft->id))->not->toBeNull();

        $restored = $service->restore(LeaveRequest::withTrashed()->find($draft->id), $ctx['schoolStaff']);

        expect($restored->trashed())->toBeFalse()
            ->and($restored->status)->toBe(LeaveStatus::DRAFT);
    });
});

// -------------------------------------------------------------------------
// API
// -------------------------------------------------------------------------

describe('سيناريوهات API للإجازات', function () {

    it('POST إنشاء + GET عرض + PUT تعديل مسودة', function () {
        $ctx = setupLeaveScenarios();
        $payload = scenarioLeavePayload($ctx);

        $create = test()->actingAs($ctx['schoolStaff'], 'sanctum')
            ->postJson('/api/leave-requests', $payload);

        $create->assertStatus(201);
        $id = $create->json('data.id');

        test()->actingAs($ctx['schoolStaff'], 'sanctum')
            ->getJson("/api/leave-requests/{$id}")
            ->assertStatus(200);

        test()->actingAs($ctx['schoolStaff'], 'sanctum')
            ->putJson("/api/leave-requests/{$id}", ['reason' => 'سبب محدث عبر API'])
            ->assertStatus(200)
            ->assertJsonPath('data.reason', 'سبب محدث عبر API');
    });

    it('POST submit ثم POST cancel عبر API', function () {
        $ctx = setupLeaveScenarios();
        $create = test()->actingAs($ctx['schoolStaff'], 'sanctum')
            ->postJson('/api/leave-requests', scenarioLeavePayload($ctx));

        $id = $create->json('data.id');

        test()->actingAs($ctx['schoolStaff'], 'sanctum')
            ->postJson("/api/leave-requests/{$id}/submit")
            ->assertStatus(200);

        test()->actingAs($ctx['schoolStaff'], 'sanctum')
            ->postJson("/api/leave-requests/{$id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', LeaveStatus::CANCELLED->value);
    });

    it('POST approve يرفض 403 لموظف بدون صلاحية اعتماد الإدارة', function () {
        $ctx = setupLeaveScenarios();
        $service = app(LeaveRequestService::class);
        $req = $service->submit(
            $service->create(scenarioLeavePayload($ctx), $ctx['schoolStaff'])->request,
            $ctx['schoolStaff']
        );
        $req = $service->approve(
            $req,
            $req->steps()->where('stage', 'direct_manager')->first(),
            $ctx['schoolManager']
        );

        $step = $req->steps()->where('stage', 'leaves_officer')->first();

        Permission::firstOrCreate(['name' => 'view_leave_requests', 'guard_name' => 'web']);
        setPermissionsTeamId($ctx['orgs']['school']->id);
        $viewer = User::factory()->inOrganization($ctx['orgs']['school'])->create();
        assignOrgRole($viewer, $ctx['orgs']['school'], 'مشاهد', ['view_leave_requests']);

        test()->actingAs($viewer, 'sanctum')
            ->postJson("/api/leave-requests/{$req->id}/approve", ['step_id' => $step->id])
            ->assertStatus(403);
    });
});
