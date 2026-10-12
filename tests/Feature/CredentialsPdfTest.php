<?php

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Services\CredentialsPdfService;
use Database\Seeders\EntitlementGradeSeeder;
use Database\Seeders\HolidaySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('generates a valid non-empty credentials PDF binary', function () {
    $service = app(CredentialsPdfService::class);
    $binary = $service->generate();

    expect($binary)->not->toBeEmpty()
        ->and(str_starts_with($binary, '%PDF'))->toBeTrue();
});

it('allows super admin and admin manager to download credentials PDF', function () {
    $this->seed(LeaveTypeSeeder::class);
    $this->seed(HolidaySeeder::class);
    $this->seed(EntitlementGradeSeeder::class);

    $directorate = Organization::create([
        'code'      => 'DIR-LXR',
        'name'      => 'مديرية الأقصر التعليمية',
        'type'      => OrganizationType::DIRECTORATE,
        'is_active' => true,
    ]);

    $administration = Organization::create([
        'code'      => 'ADM-ARM',
        'name'      => 'إدارة أرمنت التعليمية',
        'type'      => OrganizationType::ADMINISTRATION,
        'parent_id' => $directorate->id,
        'is_active' => true,
    ]);

    $this->seed(RoleAndPermissionSeeder::class);

    // 1. Super Admin by email
    $superAdmin = User::factory()->create([
        'email'           => 'admin@rtltec.com',
        'organization_id' => $directorate->id,
    ]);

    $response = $this->actingAs($superAdmin)->get(route('credentials.pdf'));
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // 2. Admin Manager (مدير الإدارة)
    $adminManager = User::factory()->create([
        'email'           => 'armant.manager@armant-schools.edu',
        'organization_id' => $administration->id,
    ]);

    setPermissionsTeamId($administration->id);
    $roleAdminManager = Role::where('name', 'مدير الإدارة')->where('organization_id', $administration->id)->first();
    $adminManager->syncRoles([$roleAdminManager]);
    setPermissionsTeamId(null);

    $response = $this->actingAs($adminManager)->get(route('credentials.pdf'));
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('denies access to unauthorized users like school managers, leaves officers, and regular users', function () {
    $this->seed(LeaveTypeSeeder::class);
    $this->seed(HolidaySeeder::class);
    $this->seed(EntitlementGradeSeeder::class);

    $directorate = Organization::create([
        'code'      => 'DIR-LXR',
        'name'      => 'مديرية الأقصر التعليمية',
        'type'      => OrganizationType::DIRECTORATE,
        'is_active' => true,
    ]);

    $administration = Organization::create([
        'code'      => 'ADM-ARM',
        'name'      => 'إدارة أرمنت التعليمية',
        'type'      => OrganizationType::ADMINISTRATION,
        'parent_id' => $directorate->id,
        'is_active' => true,
    ]);

    $school = Organization::create([
        'code'      => 'ARM001',
        'name'      => 'مدرسة أبو بكر الصديق',
        'type'      => OrganizationType::SCHOOL,
        'parent_id' => $administration->id,
        'is_active' => true,
    ]);

    $this->seed(RoleAndPermissionSeeder::class);

    // 1. School Manager (مدير مدرسة) -> 403 Forbidden
    $schoolManager = User::factory()->create([
        'email'           => 'arm001.manager@armant-schools.edu',
        'organization_id' => $school->id,
    ]);
    setPermissionsTeamId($school->id);
    $roleSchoolManager = Role::where('name', 'مدير مدرسة')->where('organization_id', $school->id)->first();
    $schoolManager->syncRoles([$roleSchoolManager]);
    setPermissionsTeamId(null);

    $this->actingAs($schoolManager)->get(route('credentials.pdf'))->assertStatus(403);

    // 2. Leaves Officer (مسؤول الإجازات) -> 403 Forbidden
    $leavesOfficer = User::factory()->create([
        'email'           => 'armant.leaves@armant-schools.edu',
        'organization_id' => $administration->id,
    ]);
    setPermissionsTeamId($administration->id);
    $roleLeaves = Role::where('name', 'مسؤول الإجازات')->where('organization_id', $administration->id)->first();
    $leavesOfficer->syncRoles([$roleLeaves]);
    setPermissionsTeamId(null);

    $this->actingAs($leavesOfficer)->get(route('credentials.pdf'))->assertStatus(403);

    // 3. Regular user without roles -> 403 Forbidden
    $regularUser = User::factory()->create([
        'email'           => 'regular@example.com',
        'organization_id' => $school->id,
    ]);

    $this->actingAs($regularUser)->get(route('credentials.pdf'))->assertStatus(403);
});
