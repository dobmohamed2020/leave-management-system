<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('denies leave export without export_reports permission', function () {
    $orgs = createHierarchy();

    Permission::firstOrCreate(['name' => 'view_leave_requests', 'guard_name' => 'web']);
    setPermissionsTeamId($orgs['school']->id);
    $role = Role::firstOrCreate([
        'name'            => 'موظف مدرسة',
        'guard_name'      => 'web',
        'organization_id' => $orgs['school']->id,
    ]);
    $role->syncPermissions(['view_leave_requests']);

    $user = User::factory()->inOrganization($orgs['school'])->create();
    $user->assignRole($role);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $response = $this->actingAs($user)->get('/leave-export');

    $response->assertStatus(403);
});

it('denies report PDF without view_reports permission', function () {
    $orgs = createHierarchy();

    Permission::firstOrCreate(['name' => 'export_reports', 'guard_name' => 'web']);
    setPermissionsTeamId($orgs['school']->id);
    $role = Role::firstOrCreate([
        'name'            => 'مصدّر',
        'guard_name'      => 'web',
        'organization_id' => $orgs['school']->id,
    ]);
    $role->syncPermissions(['export_reports']);

    $user = User::factory()->inOrganization($orgs['school'])->create();
    $user->assignRole($role);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $response = $this->actingAs($user)->get('/report-pdf');

    $response->assertStatus(403);
});
