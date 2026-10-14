<?php

use App\Enums\EntitlementGrade;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryoverRecord;
use App\Models\LeaveType;
use App\Models\Organization;
use Illuminate\Support\Facades\Artisan;

it('runs leave:year-end command in dry-run without persisting carryover records', function () {
    $orgs = createHierarchy();
    $regular = LeaveType::factory()->regular()->create();

    $employee = Employee::factory()
        ->inOrganization($orgs['school'])
        ->withGrade(EntitlementGrade::TEACHER_FIRST)
        ->create();

    LeaveBalance::create([
        'employee_id'   => $employee->id,
        'leave_type_id' => $regular->id,
        'year'          => 2024,
        'entitled'      => 30,
        'carried_over'  => 0,
        'used'          => 5,
        'used_carried'  => 0,
    ]);

    Artisan::call('leave:year-end', ['--year' => 2025, '--dry-run' => true]);

    expect(LeaveCarryoverRecord::count())->toBe(0);
});

it('leave:year-end is idempotent via carryover ledger unique constraint', function () {
    $orgs = createHierarchy();
    $regular = LeaveType::factory()->regular()->create();

    $employee = Employee::factory()
        ->inOrganization($orgs['school'])
        ->withGrade(EntitlementGrade::TEACHER_FIRST)
        ->create(['entitlement_grade' => EntitlementGrade::TEACHER_FIRST->value]);

    LeaveBalance::create([
        'employee_id'   => $employee->id,
        'leave_type_id' => $regular->id,
        'year'          => 2024,
        'entitled'      => 30,
        'carried_over'  => 0,
        'used'          => 10,
        'used_carried'  => 0,
    ]);

    $service = app(\App\Services\LeaveBalanceService::class);
    $service->carryOver($employee, 2024, 2025);
    $service->carryOver($employee, 2024, 2025);

    expect(LeaveCarryoverRecord::where('employee_id', $employee->id)->count())->toBe(1);

    $record = LeaveCarryoverRecord::first();
    expect($record->days_carried)->toBe(20)
        ->and($record->entitlement_grade)->toBe(EntitlementGrade::TEACHER_FIRST->value);
});
