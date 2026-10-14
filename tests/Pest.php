<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\EntitlementGradeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/
uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Feature: seed entitlement grades (DB-backed leave days)
|--------------------------------------------------------------------------
*/
uses()->beforeEach(function () {
    $this->seed(EntitlementGradeSeeder::class);
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Build a 3-level org hierarchy and return all nodes.
 * Bypasses OrganizationScope (no auth user in unit tests).
 */
function createHierarchy(): array
{
    $dir = Organization::create([
        'parent_id' => null,
        'type' => 'directorate',
        'name' => 'مديرية الأقصر التعليمية',
        'code' => 'DIR-TEST',
        'path' => '/',
        'depth' => 0,
        'is_active' => true,
    ]);

    $adm = Organization::create([
        'parent_id' => $dir->id,
        'type' => 'administration',
        'name' => 'إدارة أرمنت التعليمية',
        'code' => 'ADM-TEST',
        'path' => '/',
        'depth' => 0,
        'is_active' => true,
    ]);

    $school = Organization::create([
        'parent_id' => $adm->id,
        'type' => 'school',
        'name' => 'مدرسة النيل الابتدائية',
        'code' => 'SCH-TEST',
        'path' => '/',
        'depth' => 0,
        'is_active' => true,
    ]);

    // Refresh to get observer-built paths
    return [
        'directorate' => $dir->fresh(),
        'administration' => $adm->fresh(),
        'school' => $school->fresh(),
    ];
}

/**
 * Grant school-employee API permissions (matches RoleAndPermissionSeeder school_employee).
 */
function grantApiSchoolEmployeePermissions(User $user, Organization $school): void
{
    $permissions = [
        'create_leave_request',
        'edit_leave_request',
        'submit_leave_request',
        'cancel_leave_request',
        'delete_leave_request',
        'view_leave_requests',
        'view_employees',
        'create_employee',
        'edit_employee',
        'delete_employee',
    ];

    foreach ($permissions as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    setPermissionsTeamId($school->id);

    $role = Role::firstOrCreate([
        'name' => 'موظف مدرسة',
        'guard_name' => 'web',
        'organization_id' => $school->id,
    ]);

    $role->syncPermissions($permissions);
    $user->assignRole($role);

    app()[PermissionRegistrar::class]->forgetCachedPermissions();
}

/**
 * Assign a single role with permissions on an organization (Spatie teams).
 */
function assignOrgRole(
    User $user,
    Organization $org,
    string $roleName,
    array $permissions
): void {
    foreach ($permissions as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    setPermissionsTeamId($org->id);

    $role = Role::firstOrCreate([
        'name' => $roleName,
        'guard_name' => 'web',
        'organization_id' => $org->id,
    ]);

    $role->syncPermissions($permissions);
    $user->assignRole($role);

    app()[PermissionRegistrar::class]->forgetCachedPermissions();
}
