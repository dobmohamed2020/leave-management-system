<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Enums\LeaveStatus;
use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Illuminate\Support\Collection;

/**
 * صفحة تاريخ إجازات الموظف — تعرض الأرصدة والفترات الوظيفية المستنتجة
 * وإجماليات الخدمة والطلبات المعتمدة.
 */
class ViewEmployeeLeaveHistory extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EmployeeResource::class;

    protected static string $view = 'filament.pages.employee-leave-history';

    // -------------------------------------------------------------------------
    // Filter inputs (Livewire properties for wire:model)
    // -------------------------------------------------------------------------

    /** @var int|null */
    public $fromYear = null;

    /** @var int|null */
    public $toYear = null;

    // -------------------------------------------------------------------------
    // Data properties exposed to the Blade template
    // -------------------------------------------------------------------------

    public Employee $employee;

    /** @var array<string, mixed> */
    public array $employeeInfo = [];

    public Collection $balanceRows;

    public Collection $gradePeriods;

    /** @var array<string, mixed> */
    public array $summaryStats = [];

    public Collection $approvedRequests;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function mount(int|string $record): void
    {
        // Resolve the employee bypassing the OrganizationScope global scope
        $this->employee = Employee::withoutGlobalScopes()
            ->with('entitlementGrade')
            ->findOrFail($record);

        // Seed filter inputs from URL query params
        $fromParam = request()->query('from_year');
        $toParam   = request()->query('to_year');

        $this->fromYear = $fromParam !== null ? (int) $fromParam : null;
        $this->toYear   = $toParam !== null   ? (int) $toParam   : null;

        $this->loadData();
    }

    /**
     * Livewire action: re-run queries with the current fromYear / toYear values.
     */
    public function applyFilter(): void
    {
        $this->loadData();
    }

    /**
     * Livewire action: clear both year filters and reload data.
     * Using a dedicated method instead of chaining wire:click calls because
     * Livewire 3 only dispatches the first method in a semicolon-separated chain.
     */
    public function resetFilter(): void
    {
        $this->fromYear = null;
        $this->toYear   = null;
        $this->loadData();
    }

    // -------------------------------------------------------------------------
    // Page title
    // -------------------------------------------------------------------------

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'تاريخ إجازات: ' . $this->employee->full_name;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function loadData(): void
    {
        $employee = $this->employee;

        // ------- Employee info -------
        $startDate   = $employee->work_start_date ?? $employee->hire_date;

        $serviceLabel = $employee->service_duration_label;
        $serviceYears = $startDate ? (int) Carbon::parse($startDate)->diffInYears(now()) : 0;

        $this->employeeInfo = [
            'full_name'           => $employee->full_name,
            'employee_code'       => $employee->employee_code ?? '—',
            'job_title'           => $employee->job_title ?? '—',
            'grade_name'          => $employee->entitlementGrade?->name ?? ($employee->grade ?? '—'),
            'yearly_days'         => $employee->entitlementGrade?->yearly_days ?? '—',
            'hire_date'           => $employee->hire_date?->translatedFormat('d F Y') ?? '—',
            'work_start_date'     => $employee->work_start_date?->translatedFormat('d F Y') ?? '—',
            'years_of_service'    => $serviceYears,
            'service_label'       => $serviceLabel,
        ];

        // ------- Balance rows -------
        $balanceQuery = $employee->leaveBalances()
            ->with('leaveType')
            ->orderByDesc('year');

        if ($this->fromYear !== null) {
            $balanceQuery->where('year', '>=', (int) $this->fromYear);
        }
        if ($this->toYear !== null) {
            $balanceQuery->where('year', '<=', (int) $this->toYear);
        }

        $rawBalances = $balanceQuery->get();

        $this->balanceRows = $rawBalances
            ->sortByDesc('year')
            ->map(fn ($b) => [
                'year'              => $b->year,
                'leave_type_name'   => $b->leaveType?->name ?? '—',
                'entitled'          => (int) $b->entitled,
                'carried_over'      => (int) $b->carried_over,
                'used_from_carried' => (int) $b->used_from_carried,
                'used'              => (int) $b->used,
                'remaining'         => (int) $b->remaining,
            ])
            ->values();

        // ------- Grade periods (inferred from اعتيادية balances) -------
        // Use all balance rows (unfiltered by year) to infer full service periods
        $allBalances = $employee->leaveBalances()
            ->with('leaveType')
            ->get();

        $regularBalances = $allBalances->filter(
            fn ($b) => str_contains($b->leaveType?->name ?? '', 'اعتيادية')
        );

        // In the fallback path we must restrict to a single leave type to avoid
        // merging different leave types (e.g., sick + regular) into one period.
        if ($regularBalances->isNotEmpty()) {
            $sourceForPeriods = $regularBalances;
        } else {
            // No اعتيادية balances: pick the leave_type_id with the most rows
            $dominantTypeId = $allBalances
                ->groupBy('leave_type_id')
                ->sortByDesc(fn ($g) => $g->count())
                ->keys()
                ->first();

            $sourceForPeriods = $dominantTypeId !== null
                ? $allBalances->where('leave_type_id', $dominantTypeId)
                : collect();
        }

        // Split into contiguous year spans at the same entitlement level.
        // A new period starts whenever the year gap > 1 OR the entitled value changes.
        $sorted = $sourceForPeriods->sortBy('year')->values();
        $periods = [];
        $current = null;

        foreach ($sorted as $balance) {
            $year     = (int) $balance->year;
            $entitled = (int) $balance->entitled;

            if (
                $current === null
                || $current['entitled_days'] !== $entitled
                || $year - $current['to_year'] > 1          // non-contiguous gap
            ) {
                if ($current !== null) {
                    $periods[] = $current;
                }
                $current = [
                    'entitled_days' => $entitled,
                    'from_year'     => $year,
                    'to_year'       => $year,
                    'years_count'   => 1,
                ];
            } else {
                $current['to_year']    = $year;
                $current['years_count']++;
            }
        }

        if ($current !== null) {
            $periods[] = $current;
        }

        $this->gradePeriods = collect($periods)->values();

        // ------- Summary stats -------
        $totalEntitled  = $this->balanceRows->sum('entitled');
        $totalUsed      = $this->balanceRows->sum('used');
        $totalRemaining = $this->balanceRows->sum('remaining');

        $approvedCount = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::APPROVED->value)
            ->count();

        $this->summaryStats = [
            'years_of_service'        => $serviceYears,
            'service_label'           => $serviceLabel,
            'total_entitled'          => (int) $totalEntitled,
            'total_used'              => (int) $totalUsed,
            'total_remaining'         => (int) $totalRemaining,
            'approved_requests_count' => $approvedCount,
        ];

        // ------- Approved leave requests -------
        $this->approvedRequests = LeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveStatus::APPROVED->value)
            ->with('leaveType')
            ->orderByDesc('start_date')
            ->get()
            ->map(fn ($req) => [
                'number'          => $req->number,
                'leave_type_name' => $req->leaveType?->name ?? '—',
                'start_date'      => $req->start_date?->format('d/m/Y') ?? '—',
                'end_date'        => $req->end_date?->format('d/m/Y') ?? '—',
                'days'            => (int) $req->days,
                'year'            => $req->start_date?->year ?? '—',
            ])
            ->values();
    }
}
