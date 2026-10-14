<?php

use App\Enums\EntitlementGrade;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\FiscalYearService;

it('accrueAnnual stores balances under the given fiscal year', function () {
    test()->seed(\Database\Seeders\EntitlementGradeSeeder::class);
    $orgs = createHierarchy();
    LeaveType::factory()->regular()->create();

    $fiscalYear = app(FiscalYearService::class)->currentFiscalYear();
    $regular = LeaveType::where('code', 'regular')->firstOrFail();

    $employee = Employee::factory()
        ->inOrganization($orgs['school'])
        ->withGrade(EntitlementGrade::TEACHER_FIRST)
        ->create(['birth_date' => now()->subYears(30)]);

    app(\App\Services\LeaveBalanceService::class)->accrueAnnual($employee, $fiscalYear);

    expect(
        LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $regular->id)
            ->where('year', $fiscalYear)
            ->exists()
    )->toBeTrue();
});

it('API employee balances endpoint filters by current fiscal year', function () {
    test()->seed(\Database\Seeders\EntitlementGradeSeeder::class);
    $orgs = createHierarchy();
    $regular = LeaveType::factory()->regular()->create();
    $fiscalYear = app(FiscalYearService::class)->currentFiscalYear();

    $emp = Employee::factory()->inOrganization($orgs['school'])->create();
    $user = \App\Models\User::factory()->inOrganization($orgs['school'])->create();
    grantApiSchoolEmployeePermissions($user, $orgs['school']);

    LeaveBalance::create([
        'employee_id'   => $emp->id,
        'leave_type_id' => $regular->id,
        'year'          => $fiscalYear,
        'entitled'      => 30,
        'carried_over'  => 0,
        'used'          => 0,
    ]);

    LeaveBalance::create([
        'employee_id'   => $emp->id,
        'leave_type_id' => $regular->id,
        'year'          => $fiscalYear - 1,
        'entitled'      => 30,
        'carried_over'  => 5,
        'used'          => 0,
    ]);

    $response = test()->actingAs($user, 'sanctum')
        ->getJson("/api/employees/{$emp->id}/balances");

    $response->assertStatus(200);
    expect(count($response->json('data')))->toBe(1)
        ->and($response->json('data.0.year'))->toBe($fiscalYear);
});
