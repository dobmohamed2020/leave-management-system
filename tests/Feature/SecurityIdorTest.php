<?php

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\EntitlementGradeSeeder;
use Illuminate\Support\Facades\Hash;

function createSecondSchool(array $orgs): Organization
{
    return Organization::create([
        'parent_id' => $orgs['administration']->id,
        'type' => 'school',
        'name' => 'مدرسة ثانية للاختبار',
        'code' => 'SCH-TEST-2',
        'path' => '/',
        'depth' => 0,
        'is_active' => true,
    ])->fresh();
}

it('denies API view of leave request from another school in same administration', function () {
    test()->seed(EntitlementGradeSeeder::class);
    $orgs = createHierarchy();
    $schoolB = createSecondSchool($orgs);
    $regular = LeaveType::factory()->regular()->create();

    $employeeB = Employee::factory()->inOrganization($schoolB)->create();
    $otherRequest = LeaveRequest::create([
        'number' => 'LR-IDOR-001',
        'employee_id' => $employeeB->id,
        'organization_id' => $schoolB->id,
        'leave_type_id' => $regular->id,
        'start_date' => now()->addDays(10)->toDateString(),
        'end_date' => now()->addDays(12)->toDateString(),
        'days' => 3,
        'status' => LeaveStatus::DRAFT,
        'created_by' => User::factory()->inOrganization($schoolB)->create()->id,
    ]);

    $userA = User::factory()->inOrganization($orgs['school'])->create([
        'password' => Hash::make('password123'),
    ]);
    grantApiSchoolEmployeePermissions($userA, $orgs['school']);

    test()->actingAs($userA, 'sanctum')
        ->getJson("/api/leave-requests/{$otherRequest->id}")
        ->assertNotFound();
});

it('denies leave PDF download for request outside user organization subtree', function () {
    test()->seed(EntitlementGradeSeeder::class);
    $orgs = createHierarchy();
    $schoolB = createSecondSchool($orgs);
    $regular = LeaveType::factory()->regular()->create();

    $employeeB = Employee::factory()->inOrganization($schoolB)->create();
    $otherRequest = LeaveRequest::create([
        'number' => 'LR-IDOR-PDF',
        'employee_id' => $employeeB->id,
        'organization_id' => $schoolB->id,
        'leave_type_id' => $regular->id,
        'start_date' => now()->addDays(10)->toDateString(),
        'end_date' => now()->addDays(12)->toDateString(),
        'days' => 3,
        'status' => LeaveStatus::APPROVED,
        'created_by' => User::factory()->inOrganization($schoolB)->create()->id,
    ]);

    $userA = User::factory()->inOrganization($orgs['school'])->create();
    grantApiSchoolEmployeePermissions($userA, $orgs['school']);

    test()->actingAs($userA)
        ->get(route('leave.pdf.download', ['number' => $otherRequest->number]))
        ->assertForbidden();
});

it('denies API view of employee from another school', function () {
    test()->seed(EntitlementGradeSeeder::class);
    $orgs = createHierarchy();
    $schoolB = createSecondSchool($orgs);
    $employeeB = Employee::factory()->inOrganization($schoolB)->create();

    $userA = User::factory()->inOrganization($orgs['school'])->create([
        'password' => Hash::make('password123'),
    ]);
    grantApiSchoolEmployeePermissions($userA, $orgs['school']);

    test()->actingAs($userA, 'sanctum')
        ->getJson("/api/employees/{$employeeB->id}")
        ->assertNotFound();
});
