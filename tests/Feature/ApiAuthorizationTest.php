<?php

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('denies API leave creation without create_leave_request permission', function () {
    test()->seed(\Database\Seeders\EntitlementGradeSeeder::class);
    $orgs = createHierarchy();
    $regular = LeaveType::factory()->regular()->create();

    $emp = Employee::factory()->inOrganization($orgs['school'])->create();

    foreach (['view_leave_requests', 'create_leave_request'] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }
    setPermissionsTeamId($orgs['school']->id);
    $role = Role::firstOrCreate([
        'name'            => 'مشاهد فقط',
        'guard_name'      => 'web',
        'organization_id' => $orgs['school']->id,
    ]);
    $role->syncPermissions(['view_leave_requests']);

    $user = User::factory()->inOrganization($orgs['school'])->create([
        'password' => Hash::make('password123'),
    ]);
    $user->assignRole($role);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/leave-requests', [
        'employee_id'   => $emp->id,
        'leave_type_id' => $regular->id,
        'start_date'    => now()->addDays(3)->toDateString(),
        'end_date'      => now()->addDays(5)->toDateString(),
        'days'          => 3,
    ]);

    $response->assertStatus(403);
});

it('denies API employee delete without delete_employee permission', function () {
    test()->seed(\Database\Seeders\EntitlementGradeSeeder::class);
    $orgs = createHierarchy();

    $emp = Employee::factory()->inOrganization($orgs['school'])->create();
    $user = User::factory()->inOrganization($orgs['school'])->create();
    grantApiSchoolEmployeePermissions($user, $orgs['school']);

    // Remove delete permission
    setPermissionsTeamId($orgs['school']->id);
    $user->roles()->first()->revokePermissionTo('delete_employee');
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/employees/{$emp->id}");

    $response->assertStatus(403);
});
