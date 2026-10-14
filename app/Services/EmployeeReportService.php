<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceTransaction;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * خدمة إعداد بيانات التقرير الشامل للموظف
 * يتضمن: البيانات الشخصية والوظيفية، الدرجات وتاريخ الترقيات،
 * كشف رصيد الإجازات الحالي والمُرحَّل، سجل الإجازات، وسجل العمليات والتدقيق (Audit Trail).
 */
class EmployeeReportService
{
    public function __construct(
        private readonly FiscalYearService $fiscalYearService
    ) {}

    /**
     * تجميع كافة البيانات المطلوبة للتقرير الشامل للموظف.
     */
    public function getReportData(Employee $employee): array
    {
        $employee->loadMissing([
            'organization',
            'user',
            'entitlementGrade',
            'promotions.entitlementGrade',
            'promotions.creator',
            'carryoverRecords.leaveType',
        ]);

        $currentFiscalYear = $this->fiscalYearService->currentFiscalYear();
        $fiscalYearLabel   = $this->fiscalYearService->label($currentFiscalYear);

        // 1. البيانات الأساسية والوظيفية
        $basicInfo = $this->buildBasicInfo($employee);

        // 2. الدرجة الوظيفية وسجل الترقيات
        $promotionsData = $this->buildPromotionsData($employee);

        // 3. كشف أرصدة الإجازات والمُرحَّل (الحالي والتاريخي)
        $balancesData = $this->buildBalancesData($employee, $currentFiscalYear);

        // 4. سجل طلبات الإجازات المعتمدة والكلية
        $requestsData = $this->buildRequestsData($employee);

        // 5. سجل العمليات والتدقيق والتعديلات (اي داتا اتعملت للموظف)
        $auditTrail = $this->buildAuditTrail($employee);

        // 6. ملف الترحيلات (سنة، أيام، درجة الاستحقاق)
        $carryoverFile = $employee->carryoverRecords
            ->map(fn ($row) => [
                'from_fiscal_year'  => $row->from_fiscal_year,
                'to_fiscal_year'    => $row->to_fiscal_year,
                'from_label'        => $this->fiscalYearService->label($row->from_fiscal_year),
                'to_label'          => $this->fiscalYearService->label($row->to_fiscal_year),
                'leave_type'        => $row->leaveType?->name,
                'days_carried'      => $row->days_carried,
                'entitlement_grade' => $row->entitlement_grade,
                'source'            => $row->source,
            ])
            ->values()
            ->all();

        return [
            'employee'          => $employee,
            'currentFiscalYear' => $currentFiscalYear,
            'fiscalYearLabel'   => $fiscalYearLabel,
            'basicInfo'         => $basicInfo,
            'promotionsData'    => $promotionsData,
            'balancesData'      => $balancesData,
            'requestsData'      => $requestsData,
            'auditTrail'        => $auditTrail,
            'carryoverFile'     => $carryoverFile,
            'generatedAt'       => now(),
            'generatedBy'       => auth()->user()?->name ?? 'النظام',
        ];
    }

    /**
     * بناء البيانات الأساسية والوظيفية للموظف.
     */
    private function buildBasicInfo(Employee $employee): array
    {
        $startDate = $employee->work_start_date ?? $employee->hire_date;
        $age = $employee->birth_date ? (int) $employee->birth_date->diffInYears(now()) : null;

        return [
            'id'                     => $employee->id,
            'full_name'              => $employee->full_name,
            'employee_code'          => $employee->employee_code,
            'job_title'              => $employee->job_title ?? '—',
            'organization_name'      => $employee->organization?->name ?? '—',
            'organization_type'      => $employee->organization?->type?->label() ?? '—',
            'phone'                  => $employee->phone ?? '—',
            'birth_date'             => $employee->birth_date?->format('Y/m/d'),
            'age'                    => $age ? "{$age} سنة" : '—',
            'hire_date'              => $employee->hire_date?->format('Y/m/d'),
            'work_start_date'        => $employee->work_start_date?->format('Y/m/d'),
            'service_duration_label' => $employee->service_duration_label,
            'is_active'              => $employee->is_active,
            'approval_role'          => $employee->approval_role?->label(),
            'has_system_user'        => filled($employee->user_id),
            'system_user_email'      => $employee->user?->email,
        ];
    }

    /**
     * بناء بيانات الدرجة الوظيفية وسجل الترقيات.
     */
    private function buildPromotionsData(Employee $employee): array
    {
        $currentGradeName = $employee->entitlementGrade?->name ?? ($employee->grade ?? 'غير محددة');
        $yearlyEntitlement = $employee->regularLeaveEntitlement();

        $promotions = $employee->promotions()->with(['entitlementGrade', 'creator'])->get();

        $lastPromo = $promotions->first();
        $promotionDate = $employee->promotion_date ?? $lastPromo?->promotion_date;
        $decisionNumber = $employee->promotion_decision_number ?? $lastPromo?->decision_number;

        $timeInCurrentGrade = '—';
        if ($promotionDate) {
            $diff = $promotionDate->diff(now());
            $timeInCurrentGrade = "{$diff->y} سنة و {$diff->m} شهر";
        }

        return [
            'current_grade'          => $currentGradeName,
            'yearly_entitlement'     => $yearlyEntitlement,
            'promotion_date'         => $promotionDate?->format('Y/m/d'),
            'decision_number'        => $decisionNumber ?? '—',
            'time_in_current_grade'  => $timeInCurrentGrade,
            'promotions_history'     => $promotions->map(function ($promo) {
                return [
                    'id'                       => $promo->id,
                    'grade'                    => $promo->grade,
                    'entitlement_grade_name'   => $promo->entitlementGrade?->name ?? $promo->grade,
                    'promotion_date'           => $promo->promotion_date?->format('Y/m/d'),
                    'decision_number'          => $promo->decision_number ?? '—',
                    'notes'                    => $promo->notes ?? '—',
                    'created_by'               => $promo->creator?->name ?? 'النظام',
                    'duration_since_promotion' => $promo->duration_since_promotion_label,
                ];
            }),
        ];
    }

    /**
     * بناء بيانات كشف الأرصدة والمُرحَّل الحالي والتاريخي.
     */
    private function buildBalancesData(Employee $employee, int $currentFiscalYear): array
    {
        // رصيد الإجازة الاعتيادية الحالي مع عزل الرصيد المرحل
        $regularBalance = $employee->leaveBalances()
            ->whereHas('leaveType', fn ($q) => $q->where('code', 'regular'))
            ->where('year', $currentFiscalYear)
            ->first();

        $carriedOverTotal   = $regularBalance ? (int) $regularBalance->carried_over : 0;
        $usedFromCarried    = $regularBalance ? (int) $regularBalance->used_carried : 0;
        $remainingCarried   = $regularBalance ? (int) $regularBalance->remaining_carried : 0;

        $entitledAnnual     = $regularBalance ? (int) $regularBalance->entitled : $employee->regularLeaveEntitlement();
        $usedAnnual         = $regularBalance ? (int) $regularBalance->used : 0;
        $remainingAnnual    = $regularBalance ? (int) $regularBalance->remaining_entitled : $entitledAnnual;

        $totalRemaining     = $remainingAnnual + $remainingCarried;

        // جميع أرصدة السنة الحالية
        $currentYearBalances = $employee->leaveBalances()
            ->with('leaveType')
            ->where('year', $currentFiscalYear)
            ->get()
            ->map(fn ($b) => [
                'type_name'          => $b->leaveType?->name ?? '—',
                'type_code'          => $b->leaveType?->code ?? '—',
                'entitled'           => (int) $b->entitled,
                'used'               => (int) $b->used,
                'remaining_entitled' => (int) $b->remaining_entitled,
                'carried_over'       => (int) $b->carried_over,
                'used_carried'       => (int) $b->used_carried,
                'remaining_carried'  => (int) $b->remaining_carried,
                'remaining'          => (int) $b->remaining,
            ]);

        // سجل الأرصدة التاريخي عبر جميع السنوات المالية
        $allYearsBalances = $employee->leaveBalances()
            ->with('leaveType')
            ->orderByDesc('year')
            ->get()
            ->map(fn ($b) => [
                'year'              => $b->year,
                'type_name'         => $b->leaveType?->name ?? '—',
                'entitled'          => (int) $b->entitled,
                'carried_over'      => (int) $b->carried_over,
                'used_from_carried' => (int) $b->used_carried,
                'used'              => (int) $b->used,
                'remaining'         => (int) $b->remaining,
            ]);

        return [
            'regular' => [
                'entitled_annual'   => $entitledAnnual,
                'used_annual'       => $usedAnnual,
                'remaining_annual'  => $remainingAnnual,
                'carried_total'     => $carriedOverTotal,
                'used_carried'      => $usedFromCarried,
                'remaining_carried' => $remainingCarried,
                'total_remaining'   => $totalRemaining,
            ],
            'currentYearBalances' => $currentYearBalances,
            'allYearsBalances'    => $allYearsBalances,
        ];
    }

    /**
     * بناء سجل طلبات الإجازات للموظف.
     */
    private function buildRequestsData(Employee $employee): array
    {
        $requests = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->with(['leaveType'])
            ->orderByDesc('start_date')
            ->get();

        $approvedCount = $requests->where('status', LeaveStatus::APPROVED)->count();
        $approvedDays  = $requests->where('status', LeaveStatus::APPROVED)->sum('days');

        $list = $requests->map(fn ($req) => [
            'id'                  => $req->id,
            'number'              => $req->number,
            'leave_type_name'     => $req->leaveType?->name ?? '—',
            'start_date'          => $req->start_date?->format('Y/m/d'),
            'end_date'            => $req->end_date?->format('Y/m/d'),
            'days'                => (int) $req->days,
            'deduct_from_carried' => (bool) $req->deduct_from_carried,
            'status'              => $req->status->value,
            'status_label'        => $req->status->label(),
            'status_color'        => $req->status->color(),
            'reason'              => $req->reason ?? '—',
            'created_at'          => $req->created_at?->format('Y/m/d H:i'),
        ]);

        return [
            'total_count'    => $requests->count(),
            'approved_count' => $approvedCount,
            'approved_days'  => $approvedDays,
            'list'           => $list,
        ];
    }

    /**
     * بناء سجل العمليات والتدقيق والتعديلات (اي داتا اتعملت للموظف).
     */
    private function buildAuditTrail(Employee $employee): array
    {
        $timeline = collect();

        // 1. سجل نشاطات Spatie Activitylog الخاصة بالموظف
        $activities = Activity::query()
            ->where('subject_type', Employee::class)
            ->where('subject_id', $employee->id)
            ->with('causer')
            ->latest('created_at')
            ->get();

        foreach ($activities as $act) {
            $changesText = $this->formatActivityChanges($act);

            $timeline->push([
                'timestamp'   => $act->created_at,
                'date_label'  => $act->created_at?->format('Y/m/d h:i A'),
                'type'        => 'activity',
                'event'       => $act->event ?? 'updated',
                'description' => $act->description,
                'causer_name' => $act->causer?->name ?? 'النظام / مستخدم',
                'details'     => $changesText,
                'badge_color' => match ($act->event) {
                    'created' => 'success',
                    'deleted' => 'danger',
                    default   => 'info',
                },
            ]);
        }

        // 2. سجل حركات الأرصدة (تسويات، خصومات، ترحيلات)
        $balanceIds = $employee->leaveBalances()->pluck('id');
        $transactions = LeaveBalanceTransaction::query()
            ->whereIn('leave_balance_id', $balanceIds)
            ->with(['createdBy', 'leaveRequest'])
            ->latest('created_at')
            ->get();

        foreach ($transactions as $tx) {
            $timeline->push([
                'timestamp'   => $tx->created_at,
                'date_label'  => $tx->created_at?->format('Y/m/d h:i A'),
                'type'        => 'balance_transaction',
                'event'       => $tx->type->value ?? 'transaction',
                'description' => "حركة رصيد: " . ($tx->type->label() ?? 'حركة'),
                'causer_name' => $tx->createdBy?->name ?? 'النظام',
                'details'     => ($tx->days > 0 ? "+{$tx->days}" : "{$tx->days}") . " يوم — " . ($tx->note ?? 'لا توجد ملاحظات') . ($tx->leaveRequest ? " (طلب #{$tx->leaveRequest->number})" : ''),
                'badge_color' => match ($tx->type->value ?? '') {
                    'accrual', 'carryover' => 'success',
                    'usage'                => 'warning',
                    'reversal'             => 'info',
                    default                => 'gray',
                },
            ]);
        }

        // ترتيب الأحداث تنازلياً من الأحدث إلى الأقدم
        return $timeline->sortByDesc('timestamp')->values()->all();
    }

    /**
     * تنسيق التغييرات المسجلة بالنشاط وترجمتها للغة العربية.
     */
    private function formatActivityChanges(Activity $activity): string
    {
        $properties = $activity->properties;
        if (! $properties || ! isset($properties['attributes'])) {
            return $activity->description;
        }

        $attributes = $properties['attributes'] ?? [];
        $old = $properties['old'] ?? [];

        $fieldLabels = [
            'full_name'                 => 'الاسم الكامل',
            'employee_code'             => 'كود الموظف',
            'job_title'                 => 'المسمى الوظيفي',
            'grade'                     => 'الدرجة الوظيفية',
            'entitlement_grade'         => 'درجة الاستحقاق',
            'promotion_date'            => 'تاريخ الترقية',
            'promotion_decision_number' => 'رقم قرار الترقية',
            'birth_date'                => 'تاريخ الميلاد',
            'hire_date'                 => 'تاريخ التعيين',
            'work_start_date'           => 'تاريخ استلام العمل',
            'phone'                     => 'رقم الهاتف',
            'is_active'                 => 'الحالة',
            'approval_role'             => 'دور الاعتماد',
            'organization_id'           => 'جهة العمل / المدرسة',
            'user_id'                   => 'حساب الدخول المرتبط',
        ];

        $lines = [];
        foreach ($attributes as $key => $newVal) {
            $label = $fieldLabels[$key] ?? $key;
            $oldVal = $old[$key] ?? null;

            if ($key === 'is_active') {
                $newVal = $newVal ? 'نشط' : 'غير نشط';
                $oldVal = $oldVal !== null ? ($oldVal ? 'نشط' : 'غير نشط') : null;
            }

            if ($oldVal !== null && $oldVal !== $newVal) {
                $lines[] = "تعديل {$label}: من [{$oldVal}] إلى [{$newVal}]";
            } elseif ($activity->event === 'created') {
                if (filled($newVal)) {
                    $lines[] = "{$label}: {$newVal}";
                }
            }
        }

        return ! empty($lines) ? implode(' | ', $lines) : $activity->description;
    }
}
