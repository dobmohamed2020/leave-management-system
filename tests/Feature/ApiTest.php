<?php

use App\Enums\EntitlementGrade;
use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkflowConfiguration;
use Illuminate\Support\Facades\Hash;

// =============================================================================
// Helpers
// =============================================================================

function createApiUser(?Organization $org = null): User
{
    $orgs = $org ? ['school' => $org] : createHierarchy();
    $school = $org ?? $orgs['school'];

    $user = User::factory()->inOrganization($school)->create([
        'password' => Hash::make('password123'),
    ]);

    grantApiSchoolEmployeePermissions($user, $school);

    return $user;
}

function setupApiScenario(): array
{
    test()->seed(\Database\Seeders\EntitlementGradeSeeder::class);

    $orgs    = createHierarchy();
    $regular = LeaveType::factory()->regular()->create();
    $casual  = LeaveType::factory()->casual()->create();

    $emp = Employee::factory()
        ->inOrganization($orgs['school'])
        ->withGrade(EntitlementGrade::TEACHER_FIRST)
        ->create(['birth_date' => now()->subYears(35)]);

    $substitute = Employee::factory()
        ->inOrganization($orgs['school'])
        ->create(['full_name' => 'موظف بديل للاختبار']);

    $user = User::factory()->inOrganization($orgs['school'])->create([
        'password' => Hash::make('password123'),
    ]);

    grantApiSchoolEmployeePermissions($user, $orgs['school']);

    LeaveBalance::create([
        'employee_id'   => $emp->id,
        'leave_type_id' => $regular->id,
        'year'          => now()->year,
        'entitled'      => 30,
        'carried_over'  => 0,
        'used'          => 0,
    ]);

    WorkflowConfiguration::factory()->directManagerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->leavesOfficerStage($orgs['school'])->create();
    WorkflowConfiguration::factory()->adminManagerStage($orgs['school'])->create();

    return compact('orgs', 'regular', 'casual', 'emp', 'substitute', 'user');
}

// =============================================================================
// GROUP 1: Authentication
// =============================================================================

describe('API Authentication', function () {

    it('POST /api/auth/login with valid credentials returns token', function () {
        $orgs = createHierarchy();
        $user = User::factory()->inOrganization($orgs['school'])->create([
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => ['token', 'user' => ['id', 'name', 'email']],
                 ])
                 ->assertJson(['success' => true]);

        expect($response->json('data.token'))->not->toBeEmpty();
    });

    it('POST /api/auth/login with wrong password returns 401', function () {
        $orgs = createHierarchy();
        $user = User::factory()->inOrganization($orgs['school'])->create([
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
                 ->assertJson(['success' => false]);
    });

    it('POST /api/auth/login with missing email returns 422 with Arabic error', function () {
        $response = $this->postJson('/api/auth/login', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false]);

        $errors = $response->json('errors');
        $flat = collect($errors)->flatten()->implode(' ');
        expect($flat)->toContain('البريد الإلكتروني أو رقم التليفون مطلوب');
    });

    it('POST /api/auth/login with phone credentials returns token', function () {
        $orgs = createHierarchy();
        $user = User::factory()->inOrganization($orgs['school'])->create([
            'phone'    => '01012345678',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'login'    => '01012345678',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        expect($response->json('data.token'))->not->toBeEmpty();
    });

    it('GET /api/auth/me without token returns 401', function () {
        $response = $this->getJson('/api/auth/me');
        $response->assertStatus(401);
    });

    it('GET /api/auth/me with valid token returns user data', function () {
        $orgs = createHierarchy();
        $user = createApiUser($orgs['school']);

        $response = $this->actingAs($user, 'sanctum')
                         ->getJson('/api/auth/me');

        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $user->id)
                 ->assertJsonPath('data.email', $user->email);
    });

    it('POST /api/auth/logout revokes token', function () {
        $orgs = createHierarchy();
        $user = createApiUser($orgs['school']);

        $response = $this->actingAs($user, 'sanctum')
                         ->postJson('/api/auth/logout');

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);

        // Verify token was deleted from DB
        expect($user->tokens()->count())->toBe(0);
    });
});

// =============================================================================
// GROUP 2: Leave Requests API
// =============================================================================

describe('API Leave Requests', function () {

    it('GET /api/leave-requests is scoped to user organization', function () {
        $ctx = setupApiScenario();

        // Create request in user's school
        LeaveRequest::factory()->forEmployee($ctx['emp'])->create([
            'leave_type_id' => $ctx['regular']->id,
            'created_by'    => $ctx['user']->id,
        ]);

        // Create request in a different school (outside scope)
        $otherSchool = Organization::create([
            'parent_id' => $ctx['orgs']['administration']->id,
            'type'      => 'school', 'name' => 'مدرسة أخرى',
            'code'      => 'OTHER', 'path' => '/', 'depth' => 2, 'is_active' => true,
        ]);
        $otherEmp  = Employee::factory()->inOrganization($otherSchool)->create();
        $otherUser = User::factory()->inOrganization($otherSchool)->create();
        LeaveRequest::factory()->forEmployee($otherEmp)->create([
            'leave_type_id' => $ctx['regular']->id,
            'created_by'    => $otherUser->id,
        ]);

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->getJson('/api/leave-requests');

        $response->assertStatus(200);
        // Only school's request should appear
        expect(count($response->json('data')))->toBe(1);
    });

    it('POST /api/leave-requests creates a draft request', function () {
        $ctx = setupApiScenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->postJson('/api/leave-requests', [
                             'employee_id'   => $ctx['emp']->id,
                             'substitute_employee_id' => $ctx['substitute']->id,
                             'reason'                 => 'ظرف عائلي للاختبار',
                             'leave_type_id' => $ctx['regular']->id,
                             'start_date'    => now()->addDays(5)->toDateString(),
                             'end_date'      => now()->addDays(7)->toDateString(),
                             'days'          => 3,
                         ]);

        $response->assertStatus(201)
                 ->assertJson(['success' => true])
                 ->assertJsonPath('data.status', LeaveStatus::DRAFT->value);
    });

    it('POST /api/leave-requests with missing employee_id returns 422 Arabic error', function () {
        $ctx = setupApiScenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->postJson('/api/leave-requests', [
                             'leave_type_id' => $ctx['regular']->id,
                             'start_date'    => now()->addDays(5)->toDateString(),
                             'end_date'      => now()->addDays(7)->toDateString(),
                             'days'          => 3,
                         ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false])
                 ->assertJsonPath('errors.employee_id.0', 'الموظف مطلوب.');
    });

    it('POST /api/leave-requests with overlapping dates returns 422', function () {
        $ctx = setupApiScenario();

        // Create existing approved request
        LeaveRequest::factory()->forEmployee($ctx['emp'])->submitted()->create([
            'leave_type_id' => $ctx['regular']->id,
            'start_date'    => now()->addDays(5)->toDateString(),
            'end_date'      => now()->addDays(10)->toDateString(),
            'days'          => 5,
            'created_by'    => $ctx['user']->id,
        ]);

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->postJson('/api/leave-requests', [
                             'employee_id'   => $ctx['emp']->id,
                             'substitute_employee_id' => $ctx['substitute']->id,
                             'reason'                 => 'ظرف عائلي للاختبار',
                             'leave_type_id' => $ctx['regular']->id,
                             'start_date'    => now()->addDays(7)->toDateString(),
                             'end_date'      => now()->addDays(12)->toDateString(),
                             'days'          => 4,
                         ]);

        $response->assertStatus(422);
    });

    it('GET /api/leave-requests/{id} returns request with steps', function () {
        $ctx = setupApiScenario();
        $svc = app(\App\Services\LeaveRequestService::class);

        $result = $svc->create([
            'employee_id'            => $ctx['emp']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'                 => 'ظرف عائلي للاختبار',
            'leave_type_id'          => $ctx['regular']->id,
            'start_date'             => now()->addDays(10)->toDateString(),
            'end_date'               => now()->addDays(12)->toDateString(),
            'days'                   => 3,
        ], $ctx['user']);

        $svc->submit($result->request, $ctx['user']);

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->getJson("/api/leave-requests/{$result->request->id}");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['id', 'number', 'status', 'steps'],
                 ]);
    });

    it('POST /api/leave-requests/{id}/reject without reason returns 422', function () {
        $ctx = setupApiScenario();
        $svc = app(\App\Services\LeaveRequestService::class);

        $result = $svc->create([
            'employee_id'            => $ctx['emp']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'                 => 'ظرف عائلي للاختبار',
            'leave_type_id'          => $ctx['regular']->id,
            'start_date'             => now()->addDays(14)->toDateString(),
            'end_date'               => now()->addDays(16)->toDateString(),
            'days'                   => 3,
        ], $ctx['user']);
        $req = $svc->submit($result->request, $ctx['user']);
        $step = $req->steps()->where('stage', 'direct_manager')->first();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->postJson("/api/leave-requests/{$req->id}/reject", [
                             'step_id' => $step->id,
                             // missing reason
                         ]);

        $response->assertStatus(422)
                 ->assertJsonPath('errors.reason.0', 'سبب الرفض مطلوب ولا يمكن أن يكون فارغاً.');
    });

    it('POST /api/leave-requests/{id}/reject with reason sets rejected status', function () {
        $ctx = setupApiScenario();
        $svc = app(\App\Services\LeaveRequestService::class);

        $result = $svc->create([
            'employee_id'            => $ctx['emp']->id,
            'substitute_employee_id' => $ctx['substitute']->id,
            'reason'                 => 'ظرف عائلي للاختبار',
            'leave_type_id'          => $ctx['regular']->id,
            'start_date'             => now()->addDays(20)->toDateString(),
            'end_date'               => now()->addDays(22)->toDateString(),
            'days'                   => 3,
        ], $ctx['user']);
        $req   = $svc->submit($result->request, $ctx['user']);
        $step1 = $req->steps()->where('stage', 'direct_manager')->first();
        $req   = $svc->approve($req, $step1, $ctx['user']);

        $adminUser = User::factory()->inOrganization($ctx['orgs']['administration'])->create();
        foreach (['reject_leave_request', 'approve_leave_request', 'view_leave_requests'] as $perm) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        setPermissionsTeamId($ctx['orgs']['administration']->id);
        $role = \Spatie\Permission\Models\Role::firstOrCreate([
            'name'            => 'مسؤول الإجازات',
            'guard_name'      => 'web',
            'organization_id' => $ctx['orgs']['administration']->id,
        ]);
        $role->syncPermissions(['reject_leave_request', 'approve_leave_request', 'view_leave_requests']);
        $adminUser->assignRole($role);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $step2 = $req->steps()->where('stage', 'leaves_officer')->first();

        $response = $this->actingAs($adminUser, 'sanctum')
                         ->postJson("/api/leave-requests/{$req->id}/reject", [
                             'step_id' => $step2->id,
                             'reason'  => 'يوجد ضغط عمل في هذه الفترة',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', LeaveStatus::REJECTED->value)
                 ->assertJsonPath('data.rejection_reason', 'يوجد ضغط عمل في هذه الفترة');
    });
});

// =============================================================================
// GROUP 3: Employees API
// =============================================================================

describe('API Employees', function () {

    it('GET /api/employees/{id}/balances returns balance with remaining field', function () {
        $ctx = setupApiScenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->getJson("/api/employees/{$ctx['emp']->id}/balances");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => ['id', 'leave_type', 'entitled', 'carried_over', 'used', 'remaining'],
                     ],
                 ]);

        expect((float) $response->json('data.0.remaining'))->toBe(30.0);
    });

    it('POST /api/employees creates a new employee', function () {
        $ctx = setupApiScenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->postJson('/api/employees', [
                             'organization_id'   => $ctx['orgs']['school']->id,
                             'employee_code'     => 'EMP-TEST-999',
                             'full_name'         => 'أحمد محمد عبدالله',
                             'job_title'         => 'معلم أول',
                             'entitlement_grade' => EntitlementGrade::TEACHER_FIRST->value,
                         ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.full_name', 'أحمد محمد عبدالله')
                 ->assertJsonPath('data.employee_code', 'EMP-TEST-999');
    });

    it('POST /api/employees with duplicate employee_code returns 422 Arabic error', function () {
        $ctx = setupApiScenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->postJson('/api/employees', [
                             'organization_id' => $ctx['orgs']['school']->id,
                             'employee_code'   => $ctx['emp']->employee_code, // duplicate
                             'full_name'       => 'اسم آخر',
                         ]);

        $response->assertStatus(422)
                 ->assertJsonPath('errors.employee_code.0', 'كود الموظف مستخدم بالفعل.');
    });

    it('DELETE /api/employees/{id} soft deletes the employee', function () {
        $ctx = setupApiScenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->deleteJson("/api/employees/{$ctx['emp']->id}");

        $response->assertStatus(200)->assertJson(['success' => true]);

        // Verify soft deleted — use whereNull('deleted_at') to bypass SoftDeletes scope
        expect(
            Employee::withoutGlobalScopes()->whereNull('deleted_at')->find($ctx['emp']->id)
        )->toBeNull();
        expect(
            Employee::withoutGlobalScopes()->withTrashed()->find($ctx['emp']->id)
        )->not->toBeNull();
    });
});

// =============================================================================
// GROUP 4: Dashboard + Read-only APIs
// =============================================================================

describe('API Dashboard & Read-only', function () {

    it('GET /api/dashboard/stats returns school-level structure for school user', function () {
        $ctx = setupApiScenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->getJson('/api/dashboard/stats');

        $response->assertStatus(200)
                 ->assertJsonPath('data.level', 'school')
                 ->assertJsonStructure([
                     'data' => [
                         'level',
                         'status_counts',
                         'on_leave_today_count',
                         'pending_count',
                         'overdue_count',
                     ],
                 ]);
    });

    it('GET /api/leave-types returns all active leave types', function () {
        $ctx = setupApiScenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->getJson('/api/leave-types');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => ['id', 'code', 'name', 'deducts_balance'],
                     ],
                 ]);

        expect(count($response->json('data')))->toBeGreaterThanOrEqual(2);
    });

    it('GET /api/organizations returns organizations scoped to user org', function () {
        $ctx = setupApiScenario();

        $response = $this->actingAs($ctx['user'], 'sanctum')
                         ->getJson('/api/organizations');

        $response->assertStatus(200);
        // School user only sees their own school
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        expect($ids)->toContain($ctx['orgs']['school']->id);
    });
});
