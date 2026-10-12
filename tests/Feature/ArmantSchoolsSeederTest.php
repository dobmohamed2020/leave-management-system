<?php

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\ArmantCredentialsSeeder;
use Database\Seeders\ArmantSchoolsSeeder;
use Database\Seeders\EntitlementGradeSeeder;
use Database\Seeders\HolidaySeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SuperAdminSeeder;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('seeds armant schools, administrations, and users correctly via ArmantSchoolsSeeder', function () {
    $this->seed(ArmantSchoolsSeeder::class);

    // Verify Directorate
    $directorate = Organization::withoutGlobalScopes()->where('code', 'DIR-LXR')->first();
    expect($directorate)->not->toBeNull()
        ->and($directorate->name)->toBe('مديرية التربية والتعليم بالأقصر')
        ->and($directorate->type)->toBe(OrganizationType::DIRECTORATE);

    // Verify Administration
    $administration = Organization::withoutGlobalScopes()->where('code', 'ADM-ARM')->first();
    expect($administration)->not->toBeNull()
        ->and($administration->name)->toBe('إدارة أرمنت التعليمية')
        ->and($administration->parent_id)->toBe($directorate->id);

    // Verify 63 schools
    $schoolsCount = Organization::withoutGlobalScopes()->where('parent_id', $administration->id)->count();
    expect($schoolsCount)->toBe(63);

    // Verify Admin users (Director of Administration, HR Affairs, & Leave Officer)
    $manager = User::where('email', 'armant.manager@armant-schools.edu')->first();
    expect($manager)->not->toBeNull()
        ->and($manager->phone)->toBe('01000000001')
        ->and(Hash::check('123456789', $manager->password))->toBeTrue();

    $hr = User::where('email', 'armant.hr@armant-schools.edu')->first();
    expect($hr)->not->toBeNull()
        ->and($hr->phone)->toBe('01000000003')
        ->and(Hash::check('123456789', $hr->password))->toBeTrue();

    $leaves = User::where('email', 'armant.leaves@armant-schools.edu')->first();
    expect($leaves)->not->toBeNull()
        ->and($leaves->phone)->toBe('01000000002')
        ->and(Hash::check('123456789', $leaves->password))->toBeTrue();

    // Verify School users (63 managers for 63 schools + 3 admin = 66 users)
    $armUsersCount = User::where('email', 'like', '%@armant-schools.edu')->count();
    expect($armUsersCount)->toBe(66);

    // Verify first school users (manager exists, specialist does not)
    $school1Manager = User::where('email', 'arm001.manager@armant-schools.edu')->first();
    expect($school1Manager)->not->toBeNull()
        ->and($school1Manager->phone)->toBe('01000110000')
        ->and(Hash::check('123456789', $school1Manager->password))->toBeTrue();

    $school1Specialist = User::where('email', 'arm001.specialist@armant-schools.edu')->first();
    expect($school1Specialist)->toBeNull();

    // Verify last school users (arm063) (manager exists, specialist does not)
    $school63Manager = User::where('email', 'arm063.manager@armant-schools.edu')->first();
    expect($school63Manager)->not->toBeNull()
        ->and($school63Manager->phone)->toBe('01006310000')
        ->and(Hash::check('123456789', $school63Manager->password))->toBeTrue();

    $school63Specialist = User::where('email', 'arm063.specialist@armant-schools.edu')->first();
    expect($school63Specialist)->toBeNull();

    // Verify zero demo employees remain (employees table cleaned)
    expect(\App\Models\Employee::withoutGlobalScopes()->count())->toBe(0);

    // Verify finding by phone works for login
    $foundByPhone = User::findForLogin('01006310000');
    expect($foundByPhone)->not->toBeNull()
        ->and($foundByPhone->id)->toBe($school63Manager->id);

    // Verify finding by email works for login
    $foundByEmail = User::findForLogin('arm063.manager@armant-schools.edu');
    expect($foundByEmail)->not->toBeNull()
        ->and($foundByEmail->id)->toBe($school63Manager->id);
});

it('can be run via ArmantCredentialsSeeder proxy and ArmantProductionSeeder', function () {
    $this->seed(ArmantCredentialsSeeder::class);
    $this->seed(\Database\Seeders\ArmantProductionSeeder::class);

    expect(User::where('email', 'armant.manager@armant-schools.edu')->exists())->toBeTrue()
        ->and(User::where('email', 'armant.hr@armant-schools.edu')->exists())->toBeTrue()
        ->and(User::where('email', 'armant.leaves@armant-schools.edu')->exists())->toBeTrue()
        ->and(Organization::where('code', 'ARM063')->exists())->toBeTrue();
});

it('does not delete super admin when running ArmantSchoolsSeeder', function () {
    $this->seed(LeaveTypeSeeder::class);
    $this->seed(HolidaySeeder::class);
    $this->seed(EntitlementGradeSeeder::class);

    $org = Organization::create([
        'code' => 'DIR-LXR',
        'name' => 'مديرية الأقصر التعليمية',
        'type' => OrganizationType::DIRECTORATE,
        'is_active' => true,
    ]);

    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(SuperAdminSeeder::class);

    expect(User::where('email', 'admin@rtltec.com')->exists())->toBeTrue();

    $this->seed(ArmantSchoolsSeeder::class);

    expect(User::where('email', 'admin@rtltec.com')->exists())->toBeTrue();
});
