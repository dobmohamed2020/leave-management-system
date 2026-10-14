<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\LeaveBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnnualLeaveRollover extends Command
{
    /**
     * اسم وتوقيع الأمر.
     *
     * @var string
     */
    protected $signature = 'leave:year-end
                            {--year= : السنة المستهدفة (افتراضي: السنة الحالية)}
                            {--dry-run : معاينة بدون حفظ}';

    /**
     * وصف الأمر.
     *
     * @var string
     */
    protected $description = 'تجديد أرصدة الإجازات السنوية لجميع الموظفين النشطين';

    /**
     * إنشاء نسخة جديدة من الأمر.
     */
    public function __construct(private readonly LeaveBalanceService $service)
    {
        parent::__construct();
    }

    /**
     * تنفيذ الأمر.
     */
    public function handle(): int
    {
        $fiscalService = app(\App\Services\FiscalYearService::class);
        $year          = (int) ($this->option('year') ?? $fiscalService->currentFiscalYear());
        $isDryRun      = (bool) $this->option('dry-run');
        $label         = $fiscalService->label($year);

        $employees = Employee::with(['leaveBalances', 'entitlementGrade'])
            ->where('is_active', true)
            ->get();

        if ($isDryRun) {
            $this->warn("⚠️  وضع المعاينة (dry-run) — لن يتم حفظ أي تغييرات.");
            DB::beginTransaction();
        }

        $count   = 0;
        $failed  = 0;
        $started = now();

        $this->withProgressBar($employees, function (Employee $emp) use (&$count, &$failed, $year) {
            try {
                DB::transaction(function () use ($emp, $year) {
                    $this->service->carryOver($emp, $year - 1, $year);
                    $this->service->accrueAnnual($emp, $year);
                });
                $count++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('leave.year_end.employee_failed', [
                    'employee_id'        => $emp->id,
                    'target_fiscal_year' => $year,
                    'message'            => $e->getMessage(),
                ]);
                $this->warn("\nخطأ أثناء معالجة الموظف [{$emp->id}]: " . $e->getMessage());
            }
        });

        $this->newLine();

        $carryoverRecords = $isDryRun
            ? 0
            : \App\Models\LeaveCarryoverRecord::query()
                ->where('to_fiscal_year', $year)
                ->where('source', 'year_end')
                ->where('created_at', '>=', $started)
                ->count();

        $carryoverDays = $isDryRun
            ? 0
            : (int) \App\Models\LeaveCarryoverRecord::query()
                ->where('to_fiscal_year', $year)
                ->where('source', 'year_end')
                ->where('created_at', '>=', $started)
                ->sum('days_carried');

        if ($isDryRun) {
            DB::rollBack();
            $this->info("(معاينة) سيتم تجديد وتجميع الأرصدة المُرحَّلة لـ {$count} موظف للسنة المالية {$label} — لم يُحفَظ شيء.");
        } else {
            $this->info("تم تجميع الأرصدة وتجديد السنة المالية {$label} بنجاح لـ {$count} موظف.");
        }

        Log::info('leave.year_end.completed', [
            'target_fiscal_year'   => $year,
            'fiscal_year_label'    => $label,
            'employees_total'      => $employees->count(),
            'employees_processed'  => $count,
            'employees_failed'     => $failed,
            'carryover_records'    => $carryoverRecords,
            'carryover_days_total' => $carryoverDays,
            'dry_run'              => $isDryRun,
        ]);

        return Command::SUCCESS;
    }
}
