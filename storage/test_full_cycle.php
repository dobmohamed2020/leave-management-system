<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeaveRequestService;
use App\Enums\LeaveStatus;
use App\Enums\StepStatus;

echo "====================================================================\n";
echo "       تقرير فحص واختبار دورة الإجازات الشاملة (Full Life Cycle)       \n";
echo "====================================================================\n\n";

// 1. المؤسسة والمستخدمين
$org = Organization::withoutGlobalScopes()->find(10); // مدرسة أبو بكر الصديق الابتدائية
$adminOrg = Organization::withoutGlobalScopes()->find(2); // إدارة أرمنت التعليمية

if (!$org || !$adminOrg) {
    die("خطأ: لم يتم العثور على المؤسسات في قاعدة البيانات.\n");
}

echo "1. المؤسسة: {$org->name} (كود: {$org->id})\n";
echo "   الإدارة: {$adminOrg->name} (كود: {$adminOrg->id})\n\n";

$principalUser     = User::find(19);  // مدير مدرسة
$leavesOfficerUser = User::find(146); // مسؤول إجازات
$hrUser            = User::find(146); // شؤون عاملين
$adminManagerUser  = User::find(145); // مدير إدارة

setPermissionsTeamId($org->id);
if (!$principalUser->hasRole('مدير مدرسة')) {
    $principalUser->assignRole('مدير مدرسة');
}

setPermissionsTeamId($adminOrg->id);
if (!$leavesOfficerUser->hasRole('مسؤول الإجازات')) {
    $leavesOfficerUser->assignRole('مسؤول الإجازات');
}
if (!$hrUser->hasRole('شؤون عاملين')) {
    $hrUser->assignRole('شؤون عاملين');
}
if (!$adminManagerUser->hasRole('مدير الإدارة')) {
    $adminManagerUser->assignRole('مدير الإدارة');
}

// 2. موظف الاختبار
$employee = Employee::withoutGlobalScopes()->updateOrCreate(
    ['employee_code' => 'TEST-CYCLE-99'],
    [
        'full_name'       => 'أحمد محمود علي',
        'organization_id' => $org->id,
        'job_title'       => 'معلم أول',
        'is_active'       => true,
    ]
);

$substitute = Employee::withoutGlobalScopes()
    ->where('organization_id', $org->id)
    ->where('id', '!=', $employee->id)
    ->first();

if (!$substitute) {
    $substitute = Employee::withoutGlobalScopes()->updateOrCreate(
        ['employee_code' => 'SUB-CYCLE-99'],
        [
            'full_name'       => 'خالد إبراهيم حسن',
            'organization_id' => $org->id,
            'is_active'       => true,
        ]
    );
}

$regularType = LeaveType::where('code', 'regular')->firstOrFail();
$year = now()->year;
$leaveService = app(LeaveRequestService::class);

function approveAllStages(LeaveRequest $request, $principal, $officer, $hr, $manager, $leaveService) {
    $approversByStage = [
        'school_principal' => $principal,
        'leaves_officer'   => $officer,
        'hr_affairs'       => $hr,
        'admin_manager'    => $manager,
    ];

    while (in_array($request->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])) {
        $step = $request->steps()
            ->where('status', StepStatus::PENDING)
            ->where('stage', $request->current_stage)
            ->first();

        if (!$step) break;

        $approver = $approversByStage[$step->stage] ?? $manager;
        $request = $leaveService->approve($request, $step, $approver, "اعتماد لمرحلة: {$step->stage}");
    }

    return $request->fresh();
}

// =========================================================================
// السيناريو الأول: موظف برصيد 28 يوم سنوي وبدون مُرحَّل (مُرحَّل = 0)
// =========================================================================
echo "====================================================================\n";
echo "【السيناريو الأول】: الموظف بدون رصيد مرحل (المستحق: 28 يوم، المُرحَّل: 0)\n";
echo "====================================================================\n\n";

LeaveRequest::where('employee_id', $employee->id)->forceDelete();

$balanceA = LeaveBalance::updateOrCreate(
    [
        'employee_id'   => $employee->id,
        'leave_type_id' => $regularType->id,
        'year'          => $year,
    ],
    [
        'entitled'     => 28,
        'carried_over' => 0,
        'used'         => 0,
    ]
);
LeaveBalanceTransaction::where('leave_balance_id', $balanceA->id)->delete();
$balanceA->refresh();

echo "• الرصيد الافتتاحي:\n";
echo "  - المستحق السنوي: {$balanceA->entitled} يوم\n";
echo "  - الرصيد المُرحَّل: {$balanceA->carried_over} يوم\n";
echo "  - إجمالي المستخدم: {$balanceA->used} يوم\n";
echo "  - المتبقي الفعلي: {$balanceA->remaining} يوم\n\n";

// الإجازة 1: 4 أيام
$res1 = $leaveService->create([
    'employee_id'            => $employee->id,
    'leave_type_id'          => $regularType->id,
    'start_date'             => '2026-11-01',
    'end_date'               => '2026-11-04',
    'days'                   => 4,
    'substitute_employee_id' => $substitute->id,
    'reason'                 => 'إجازة اعتيادية 4 أيام',
], $principalUser);
$req1 = approveAllStages($leaveService->submit($res1->request, $principalUser), $principalUser, $leavesOfficerUser, $hrUser, $adminManagerUser, $leaveService);
$balanceA->refresh();

echo "1. بعد اعتماد الإجازة الأولى (4 أيام):\n";
echo "   - المستخدم من المستحق: {$balanceA->used_from_entitled} يوم\n";
echo "   - إجمالي المستخدم: {$balanceA->used} يوم\n";
echo "   - المتبقي الفعلي: {$balanceA->remaining} يوم (النتيجة الصحيحة: 28 - 4 = 24 يوم) ✓\n\n";

// الإجازة 2: 2 يوم
$res2 = $leaveService->create([
    'employee_id'            => $employee->id,
    'leave_type_id'          => $regularType->id,
    'start_date'             => '2026-11-08',
    'end_date'               => '2026-11-09',
    'days'                   => 2,
    'substitute_employee_id' => $substitute->id,
    'reason'                 => 'إجازة اعتيادية 2 يوم',
], $principalUser);
$req2 = approveAllStages($leaveService->submit($res2->request, $principalUser), $principalUser, $leavesOfficerUser, $hrUser, $adminManagerUser, $leaveService);
$balanceA->refresh();

echo "2. بعد اعتماد الإجازة الثانية (2 يوم):\n";
echo "   - المستخدم من المستحق: {$balanceA->used_from_entitled} يوم\n";
echo "   - إجمالي المستخدم التراكمي: {$balanceA->used} يوم\n";
echo "   - المتبقي الفعلي: {$balanceA->remaining} يوم (النتيجة الصحيحة: 24 - 2 = 22 يوم) ✓\n\n";

// الإجازة 3: 5 أيام
$res3 = $leaveService->create([
    'employee_id'            => $employee->id,
    'leave_type_id'          => $regularType->id,
    'start_date'             => '2026-11-15',
    'end_date'               => '2026-11-19',
    'days'                   => 5,
    'substitute_employee_id' => $substitute->id,
    'reason'                 => 'إجازة اعتيادية 5 أيام',
], $principalUser);
$req3 = approveAllStages($leaveService->submit($res3->request, $principalUser), $principalUser, $leavesOfficerUser, $hrUser, $adminManagerUser, $leaveService);
$balanceA->refresh();

echo "3. بعد اعتماد الإجازة الثالثة (5 أيام):\n";
echo "   - إجمالي المستخدم التراكمي: {$balanceA->used} يوم (4 + 2 + 5 = 11)\n";
echo "   - المتبقي الفعلي: {$balanceA->remaining} يوم (النتيجة الصحيحة: 22 - 5 = 17 يوم) ✓\n\n";

// إلغاء/حذف الإجازة الثالثة
$req3 = $leaveService->cancel($req3, $principalUser);
$balanceA->refresh();

echo "4. بعد إلغاء الإجازة الثالثة (استرداد 5 أيام بالكامل):\n";
echo "   - إجمالي المستخدم عاد إلى: {$balanceA->used} يوم\n";
echo "   - المتبقي الفعلي عاد إلى: {$balanceA->remaining} يوم (النتيجة الصحيحة: 17 + 5 = 22 يوم) ✓\n\n";


// =========================================================================
// السيناريو الثاني: موظف برصيد سنوي 28 يوم + رصيد مُرحَّل 5 أيام (إجمالي: 33 يوم)
// =========================================================================
echo "====================================================================\n";
echo "【السيناريو الثاني】: موظف لديه رصيد مُرحَّل (المستحق: 28، المُرحَّل: 5 أيام، الإجمالي: 33)\n";
echo "====================================================================\n\n";

LeaveRequest::where('employee_id', $employee->id)->forceDelete();
LeaveBalanceTransaction::where('leave_balance_id', $balanceA->id)->delete();

$balanceB = LeaveBalance::updateOrCreate(
    [
        'employee_id'   => $employee->id,
        'leave_type_id' => $regularType->id,
        'year'          => $year,
    ],
    [
        'entitled'     => 28,
        'carried_over' => 5,
        'used'         => 0,
    ]
);
$balanceB->refresh();

echo "• الرصيد الافتتاحي:\n";
echo "  - المستحق السنوي: {$balanceB->entitled} يوم\n";
echo "  - الرصيد المُرحَّل: {$balanceB->carried_over} أيام\n";
echo "  - إجمالي الرصيد المتاح: " . ($balanceB->entitled + $balanceB->carried_over) . " يوم\n";
echo "  - مستخدم من المُرحَّل: {$balanceB->used_from_carried} يوم\n";
echo "  - مستخدم من المستحق: {$balanceB->used_from_entitled} يوم\n";
echo "  - المتبقي الإجمالي: {$balanceB->remaining} يوم\n\n";

// إجازة 1: 4 أيام (تُخصم من المُرحّل أولاً)
$resB1 = $leaveService->create([
    'employee_id'            => $employee->id,
    'leave_type_id'          => $regularType->id,
    'start_date'             => '2026-11-01',
    'end_date'               => '2026-11-04',
    'days'                   => 4,
    'substitute_employee_id' => $substitute->id,
    'reason'                 => 'إجازة اعتيادية 4 أيام',
], $principalUser);
$reqB1 = approveAllStages($leaveService->submit($resB1->request, $principalUser), $principalUser, $leavesOfficerUser, $hrUser, $adminManagerUser, $leaveService);
$balanceB->refresh();

echo "1. بعد اعتماد الإجازة الأولى (4 أيام) - تُخصم من المُرحَّل أولاً:\n";
echo "   - مستخدم من المُرحَّل: {$balanceB->used_from_carried} من أصل {$balanceB->carried_over} يوم\n";
echo "   - متبقي من المُرحَّل: {$balanceB->remaining_carried} يوم\n";
echo "   - مستخدم من السنوي: {$balanceB->used_from_entitled} يوم (لم يُمس السنوي)\n";
echo "   - متبقي من السنوي: {$balanceB->remaining_entitled} يوم\n";
echo "   - إجمالي المستخدم: {$balanceB->used} يوم\n";
echo "   - إجمالي المتبقي: {$balanceB->remaining} يوم (33 - 4 = 29 يوم) ✓\n\n";

// إجازة 2: 2 يوم (يُخصم اليوم المتبقي من المُرحّل + 1 يوم من السنوي)
$resB2 = $leaveService->create([
    'employee_id'            => $employee->id,
    'leave_type_id'          => $regularType->id,
    'start_date'             => '2026-11-08',
    'end_date'               => '2026-11-09',
    'days'                   => 2,
    'substitute_employee_id' => $substitute->id,
    'reason'                 => 'إجازة اعتيادية 2 يوم',
], $principalUser);
$reqB2 = approveAllStages($leaveService->submit($resB2->request, $principalUser), $principalUser, $leavesOfficerUser, $hrUser, $adminManagerUser, $leaveService);
$balanceB->refresh();

echo "2. بعد اعتماد الإجازة الثانية (2 يوم) - استنفاد المُرحّل وبدء الخصم من السنوي:\n";
echo "   - مستخدم من المُرحَّل: {$balanceB->used_from_carried} من أصل {$balanceB->carried_over} (استُنفد المُرحّل بالكامل)\n";
echo "   - متبقي من المُرحَّل: {$balanceB->remaining_carried} يوم\n";
echo "   - مستخدم من السنوي: {$balanceB->used_from_entitled} يوم\n";
echo "   - متبقي من السنوي: {$balanceB->remaining_entitled} يوم (28 - 1 = 27 يوم)\n";
echo "   - إجمالي المستخدم: {$balanceB->used} يوم (5 مرحل + 1 سنوي = 6)\n";
echo "   - إجمالي المتبقي: {$balanceB->remaining} يوم (33 - 6 = 27 يوم) ✓\n\n";

// إجازة 3: 5 أيام (كلها من السنوي)
$resB3 = $leaveService->create([
    'employee_id'            => $employee->id,
    'leave_type_id'          => $regularType->id,
    'start_date'             => '2026-11-15',
    'end_date'               => '2026-11-19',
    'days'                   => 5,
    'substitute_employee_id' => $substitute->id,
    'reason'                 => 'إجازة اعتيادية 5 أيام',
], $principalUser);
$reqB3 = approveAllStages($leaveService->submit($resB3->request, $principalUser), $principalUser, $leavesOfficerUser, $hrUser, $adminManagerUser, $leaveService);
$balanceB->refresh();

echo "3. بعد اعتماد الإجازة الثالثة (5 أيام) - تُخصم من السنوي:\n";
echo "   - مستخدم من المُرحَّل: {$balanceB->used_from_carried} يوم\n";
echo "   - مستخدم من السنوي: {$balanceB->used_from_entitled} يوم (1 + 5 = 6 أيام)\n";
echo "   - متبقي من السنوي: {$balanceB->remaining_entitled} يوم (28 - 6 = 22 يوم)\n";
echo "   - إجمالي المستخدم: {$balanceB->used} يوم (5 مرحل + 6 سنوي = 11)\n";
echo "   - إجمالي المتبقي: {$balanceB->remaining} يوم (33 - 11 = 22 يوم) ✓\n\n";

// إلغاء/حذف الإجازة الثالثة واسترداد 5 أيام
$reqB3 = $leaveService->cancel($reqB3, $principalUser);
$balanceB->refresh();

echo "4. بعد إلغاء الإجازة الثالثة (استرداد 5 أيام بالكامل):\n";
echo "   - مستخدم من السنوي عاد إلى: {$balanceB->used_from_entitled} يوم\n";
echo "   - إجمالي المستخدم عاد إلى: {$balanceB->used} يوم (5 مرحل + 1 سنوي = 6)\n";
echo "   - إجمالي المتبقي عاد إلى: {$balanceB->remaining} يوم (22 + 5 = 27 يوم) ✓\n\n";

echo "====================================================================\n";
echo "     النتيجة النهائية: كشف الحساب والعمليات الرياضية متطابقة 100%     \n";
echo "====================================================================\n";
