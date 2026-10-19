<?php

use App\Enums\ApprovalRole;
use App\Enums\OrganizationType;
use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Organization;
use App\Models\User;
use App\Policies\LeaveRequestPolicy;
use Database\Seeders\EntitlementGradeSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows administration user to see administration and schools in organization options', function () {
    $directorate = Organization::create([
        'code' => 'DIR-LXR',
        'name' => 'مديرية التربية والتعليم بالأقصر',
        'type' => OrganizationType::DIRECTORATE,
        'is_active' => true,
    ]);

    $admin = Organization::create([
        'code' => 'ADM-ARM',
        'name' => 'إدارة أرمنت التعليمية',
        'type' => OrganizationType::ADMINISTRATION,
        'parent_id' => $directorate->id,
        'is_active' => true,
    ]);

    $school = Organization::create([
        'code' => 'ARM001',
        'name' => 'مدرسة أبو بكر الصديق الابتدائية',
        'type' => OrganizationType::SCHOOL,
        'parent_id' => $admin->id,
        'is_active' => true,
    ]);

    $adminManager = User::create([
        'name' => 'مدير إدارة أرمنت',
        'email' => 'armant.manager@armant-schools.edu',
        'organization_id' => $admin->id,
        'password' => '123456789',
        'is_active' => true,
    ]);

    $this->actingAs($adminManager);

    $options = EmployeeResource::organizationOptions();

    // Verify Administration itself is present
    expect(array_key_exists($admin->id, $options))->toBeTrue()
        ->and($options[$admin->id])->toContain('إدارة أرمنت التعليمية')
        ->and(array_key_exists($school->id, $options))->toBeTrue()
        ->and($options[$school->id])->toBe('مدرسة أبو بكر الصديق الابتدائية');
});

it('provisions system user with hr_affairs role in administration and grants panel access', function () {
    $this->seed(LeaveTypeSeeder::class);
    $this->seed(EntitlementGradeSeeder::class);

    $directorate = Organization::create([
        'code' => 'DIR-LXR',
        'name' => 'مديرية التربية والتعليم بالأقصر',
        'type' => OrganizationType::DIRECTORATE,
        'is_active' => true,
    ]);

    $admin = Organization::create([
        'code' => 'ADM-ARM',
        'name' => 'إدارة أرمنت التعليمية',
        'type' => OrganizationType::ADMINISTRATION,
        'parent_id' => $directorate->id,
        'is_active' => true,
    ]);

    $school = Organization::create([
        'code' => 'ARM001',
        'name' => 'مدرسة أبو بكر الصديق الابتدائية',
        'type' => OrganizationType::SCHOOL,
        'parent_id' => $admin->id,
        'is_active' => true,
    ]);

    RoleAndPermissionSeeder::seedForOrganization($admin);

    $employee = Employee::create([
        'organization_id' => $admin->id,
        'employee_code' => 'EMP-HR001',
        'full_name' => 'موظف شؤون عاملين',
        'job_title' => 'إداري',
        'approval_role' => ApprovalRole::HR_AFFAIRS,
        'is_active' => true,
    ]);

    $user = EmployeeResource::provisionSystemUser(
        employee: $employee,
        email: 'hr.armant@armant-schools.edu',
        password: 'password123',
        phone: '01012345678'
    );

    expect($user)->not->toBeNull()
        ->and($user->organization_id)->toBe($admin->id);

    setPermissionsTeamId($admin->id);
    expect($user->hasRole('شؤون عاملين'))->toBeTrue();

    // Verify canAccessPanel returns true for HR Affairs
    $panel = Mockery::mock(Panel::class);
    expect($user->canAccessPanel($panel))->toBeTrue();

    // Verify user can take action on hr_affairs stage
    $leaveRequest = new LeaveRequest;
    $leaveRequest->organization_id = $school->id;
    $leaveRequest->current_stage = 'hr_affairs';

    $policy = new LeaveRequestPolicy;
    expect($policy->approve($user, $leaveRequest))->toBeTrue();
});
