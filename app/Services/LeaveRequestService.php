<?php

namespace App\Services;

use App\Enums\ApprovalRule;
use App\Enums\LeaveStatus;
use App\Enums\StepStatus;
use App\Exceptions\LeaveRequestException;
use App\Models\DeletionRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkflowConfiguration;
use App\Notifications\LeaveRequestNotification;
use App\Rules\Leave\CasualBeforeRegularRule;
use App\Rules\Leave\MaxDaysPerRequestRule;
use App\Rules\Leave\NoOverlapRule;
use App\Rules\Leave\SufficientBalanceRule;
use App\Rules\Leave\WorkingDaysRule;
use App\Services\DTO\CreateLeaveRequestResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    public function __construct(
        private readonly LeaveBalanceService $balanceService,
        private readonly AuditLogService $auditLog,
    ) {}

    // -------------------------------------------------------------------------
    // Create (Draft)
    // -------------------------------------------------------------------------

    /**
     * Validate and create a draft leave request.
     * Returns a DTO with the request + any non-blocking warnings.
     *
     * @param  array<string, mixed>  $data  Must contain: employee_id, leave_type_id,
     *                                      start_date, end_date, days, (optional) reason,
     *                                      written_at, substitute_employee_id
     *
     * @throws ValidationException
     */
    public function create(array $data, User $createdBy): CreateLeaveRequestResult
    {
        $employee = Employee::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->find($data['employee_id'] ?? null);

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'الموظف المحدد غير موجود أو تم حذفه.',
            ]);
        }

        $leaveType = LeaveType::findOrFail($data['leave_type_id']);
        $fiscalYear = app(FiscalYearService::class)->fiscalYearFor(
            isset($data['start_date']) ? Carbon::parse($data['start_date']) : null
        );
        $deductFromCarried = (bool) ($data['deduct_from_carried'] ?? false);

        if ($deductFromCarried && ! $leaveType->isRegular()) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'خصم الإجازة من الرصيد المُرحَّل متاح فقط للإجازات الاعتيادية.',
            ]);
        }

        // ---- Date validations ----
        if (empty($data['start_date'])) {
            throw ValidationException::withMessages([
                'start_date' => 'تاريخ البداية مطلوب.',
            ]);
        }

        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $allowPastDates = (bool) ($data['allow_past_dates'] ?? false);
        if (! $allowPastDates && $startDate->isPast() && ! $startDate->isToday()) {
            throw ValidationException::withMessages([
                'start_date' => 'لا يمكن تقديم طلب إجازة بتاريخ قديم في الماضي.',
            ]);
        }

        if (empty($data['end_date'])) {
            throw ValidationException::withMessages([
                'end_date' => 'تاريخ النهاية مطلوب.',
            ]);
        }

        $endDate = Carbon::parse($data['end_date'])->startOfDay();
        if ($endDate->lt($startDate)) {
            throw ValidationException::withMessages([
                'end_date' => 'تاريخ النهاية يجب أن يكون في نفس تاريخ البداية أو بعده.',
            ]);
        }

        $warnings = [];

        // ---- Working days calculation ----
        $workingDaysRule = new WorkingDaysRule;
        $workingDaysRule->setData($data);
        $workingDaysRule->validate('days', $data['days'] ?? 0, fn ($msg) => null);

        if ($workingDaysRule->calculatedDays > 0) {
            $data['days'] = $workingDaysRule->calculatedDays;
        } elseif (empty($data['days']) || (int) $data['days'] <= 0) {
            throw ValidationException::withMessages([
                'start_date' => 'لا توجد أيام عمل في الفترة المحددة (عطلة أسبوعية أو إجازة رسمية). اختر تواريخاً أخرى.',
                'days' => 'عدد أيام الإجازة لا يمكن أن يقل عن يوم واحد.',
            ]);
        }

        if ((int) $data['days'] < 1) {
            throw ValidationException::withMessages([
                'days' => 'عدد أيام الإجازة لا يمكن أن يقل عن يوم واحد.',
            ]);
        }

        // ---- Sufficient balance ----
        $balanceRule = new SufficientBalanceRule($employee, $leaveType, $fiscalYear, $deductFromCarried);
        $balanceRule->validate('days', $data['days'], fn ($msg) => throw ValidationException::withMessages(['days' => $msg]));
        if ($balanceRule->warning) {
            $warnings[] = $balanceRule->warning;
        }

        // ---- No overlap ----
        $overlapMessages = [];
        $noOverlapRule = new NoOverlapRule($employee->id);
        $noOverlapRule->setData($data);
        $noOverlapRule->validate('days', $data['days'], function ($msg) use (&$overlapMessages) {
            $overlapMessages[] = $msg;
        });
        if (! empty($overlapMessages)) {
            throw ValidationException::withMessages(['start_date' => $overlapMessages]);
        }

        // ---- Max days per request ----
        $maxDaysMessages = [];
        $maxRule = new MaxDaysPerRequestRule($leaveType);
        $maxRule->validate('days', $data['days'], function ($msg) use (&$maxDaysMessages) {
            $maxDaysMessages[] = $msg;
        });
        if (! empty($maxDaysMessages)) {
            throw ValidationException::withMessages(['days' => $maxDaysMessages]);
        }

        // ---- Casual before regular ----
        $casualRule = new CasualBeforeRegularRule($employee, $leaveType, $fiscalYear);
        $casualRule->validate('days', $data['days'], fn ($msg) => null);
        if ($casualRule->warning) {
            $warnings[] = $casualRule->warning;
        }

        // ---- Substitute (optional) + reason (optional) ----
        $substituteId = $data['substitute_employee_id'] ?? null;
        if (! empty($substituteId)) {
            if ((int) $substituteId === (int) $employee->id) {
                throw ValidationException::withMessages([
                    'substitute_employee_id' => 'لا يمكن أن يكون الموظف البديل هو نفس طالب الإجازة.',
                ]);
            }

            $substituteExists = Employee::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereKey($substituteId)
                ->exists();

            if (! $substituteExists) {
                throw ValidationException::withMessages([
                    'substitute_employee_id' => 'الموظف البديل المحدد غير موجود أو تم حذفه.',
                ]);
            }
        }

        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
        $data['reason'] = ($reason !== '') ? $reason : null;

        // ---- Persist ----
        $request = DB::transaction(function () use ($data, $createdBy, $employee, $deductFromCarried) {
            return LeaveRequest::create([
                'employee_id' => $employee->id,
                'organization_id' => $employee->organization_id,
                'leave_type_id' => $data['leave_type_id'],
                'deduct_from_carried' => $deductFromCarried,
                'substitute_employee_id' => $data['substitute_employee_id'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $data['days'],
                'written_at' => $data['written_at'] ?? null,
                'reason' => isset($data['reason']) ? trim((string) $data['reason']) : null,
                'status' => LeaveStatus::DRAFT,
                'created_by' => $createdBy->id,
            ]);
        });

        $fresh = $request->fresh();
        $this->auditLeaveWorkflow($fresh, $createdBy, 'إنشاء مسودة طلب إجازة');

        return new CreateLeaveRequestResult($fresh, $warnings);
    }

    /**
     * Validate and persist updates to a draft/returned leave request.
     *
     * @param  array<string, mixed>  $patch
     *
     * @throws ValidationException
     */
    public function update(LeaveRequest $leaveRequest, array $patch, User $actedBy): LeaveRequest
    {
        if (! $leaveRequest->status->canBeEdited() && ! $leaveRequest->status->canBeModifiedBySchool()) {
            throw ValidationException::withMessages([
                'status' => "لا يمكن تعديل الطلب في حالته الحالية ({$leaveRequest->status->label()}).",
            ]);
        }

        $patch = array_filter($patch, fn ($v) => $v !== null);

        $data = array_merge([
            'employee_id' => $leaveRequest->employee_id,
            'leave_type_id' => $leaveRequest->leave_type_id,
            'start_date' => $leaveRequest->start_date->toDateString(),
            'end_date' => $leaveRequest->end_date->toDateString(),
            'days' => $leaveRequest->days,
            'reason' => $leaveRequest->reason,
            'substitute_employee_id' => $leaveRequest->substitute_employee_id,
            'deduct_from_carried' => $leaveRequest->deduct_from_carried,
            'written_at' => $leaveRequest->written_at?->toDateString(),
            'allow_past_dates' => true,
            'exclude_request_id' => $leaveRequest->id,
        ], $patch);

        $employee = Employee::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->find($data['employee_id']);

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'الموظف المحدد غير موجود أو تم حذفه.',
            ]);
        }

        $leaveType = LeaveType::findOrFail($data['leave_type_id']);
        $fiscalYear = app(FiscalYearService::class)->fiscalYearFor(
            isset($data['start_date']) ? Carbon::parse($data['start_date']) : null
        );
        $deductFromCarried = (bool) ($data['deduct_from_carried'] ?? false);

        if ($deductFromCarried && ! $leaveType->isRegular()) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'خصم الإجازة من الرصيد المُرحَّل متاح فقط للإجازات الاعتيادية.',
            ]);
        }

        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $allowPastDates = (bool) ($data['allow_past_dates'] ?? false);
        if (! $allowPastDates && $startDate->isPast() && ! $startDate->isToday()) {
            throw ValidationException::withMessages([
                'start_date' => 'لا يمكن تقديم طلب إجازة بتاريخ قديم في الماضي.',
            ]);
        }

        $endDate = Carbon::parse($data['end_date'])->startOfDay();
        if ($endDate->lt($startDate)) {
            throw ValidationException::withMessages([
                'end_date' => 'تاريخ النهاية يجب أن يكون في نفس تاريخ البداية أو بعده.',
            ]);
        }

        $workingDaysRule = new WorkingDaysRule;
        $workingDaysRule->setData($data);
        $workingDaysRule->validate('days', $data['days'] ?? 0, fn ($msg) => null);

        if ($workingDaysRule->calculatedDays > 0) {
            $data['days'] = $workingDaysRule->calculatedDays;
        } elseif (empty($data['days']) || (int) $data['days'] <= 0) {
            throw ValidationException::withMessages([
                'start_date' => 'لا توجد أيام عمل في الفترة المحددة (عطلة أسبوعية أو إجازة رسمية). اختر تواريخاً أخرى.',
                'days' => 'عدد أيام الإجازة لا يمكن أن يقل عن يوم واحد.',
            ]);
        }

        $balanceRule = new SufficientBalanceRule($employee, $leaveType, $fiscalYear, $deductFromCarried);
        $balanceRule->validate('days', $data['days'], fn ($msg) => throw ValidationException::withMessages(['days' => $msg]));

        $excludeId = (int) ($data['exclude_request_id'] ?? $leaveRequest->id);
        $overlapMessages = [];
        $noOverlapRule = new NoOverlapRule($employee->id, $excludeId);
        $noOverlapRule->setData($data);
        $noOverlapRule->validate('days', $data['days'], function ($msg) use (&$overlapMessages) {
            $overlapMessages[] = $msg;
        });
        if (! empty($overlapMessages)) {
            throw ValidationException::withMessages(['start_date' => $overlapMessages]);
        }

        $maxDaysMessages = [];
        $maxRule = new MaxDaysPerRequestRule($leaveType);
        $maxRule->validate('days', $data['days'], function ($msg) use (&$maxDaysMessages) {
            $maxDaysMessages[] = $msg;
        });
        if (! empty($maxDaysMessages)) {
            throw ValidationException::withMessages(['days' => $maxDaysMessages]);
        }

        $substituteId = $data['substitute_employee_id'] ?? null;
        if (! empty($substituteId) && (int) $substituteId === (int) $employee->id) {
            throw ValidationException::withMessages([
                'substitute_employee_id' => 'لا يمكن أن يكون الموظف البديل هو نفس طالب الإجازة.',
            ]);
        }

        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
        $data['reason'] = ($reason !== '') ? $reason : null;

        $leaveRequest->update([
            'employee_id' => $employee->id,
            'organization_id' => $employee->organization_id,
            'leave_type_id' => $data['leave_type_id'],
            'deduct_from_carried' => $deductFromCarried,
            'substitute_employee_id' => $data['substitute_employee_id'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'days' => $data['days'],
            'written_at' => $data['written_at'] ?? null,
            'reason' => $data['reason'],
        ]);

        $fresh = $leaveRequest->fresh();
        $this->auditLeaveWorkflow($fresh, $actedBy, 'تعديل طلب إجازة');

        return $fresh;
    }

    // -------------------------------------------------------------------------
    // Submit
    // -------------------------------------------------------------------------

    /**
     * Transition a draft/returned request to SUBMITTED and create workflow steps.
     *
     * @throws LeaveRequestException
     */
    public function submit(LeaveRequest $request, User $submittedBy): LeaveRequest
    {
        if (! in_array($request->status, [LeaveStatus::DRAFT, LeaveStatus::RETURNED])) {
            throw LeaveRequestException::invalidTransition($request->status, LeaveStatus::SUBMITTED);
        }

        $result = DB::transaction(function () use ($request, $submittedBy) {
            // Delete any stale steps from a previous submission
            $request->steps()->delete();

            $employee = Employee::withoutGlobalScopes()
                ->withTrashed()
                ->find($request->employee_id);

            if (! $employee) {
                throw new LeaveRequestException('لا يمكن تقديم الطلب: بيانات الموظف غير موجودة.');
            }

            // Snapshot current balance
            $leaveType = $request->leaveType;
            $fiscalYear = app(FiscalYearService::class)->fiscalYearFor($request->start_date);
            $balance = $this->balanceService->getOrCreateBalance(
                $employee,
                $leaveType,
                $fiscalYear
            );

            $request->balance_entitled = $balance->entitled;
            $request->balance_used = $balance->used;
            $request->balance_remaining = $balance->remaining;

            // Build workflow steps from configuration
            $stages = WorkflowConfiguration::withoutGlobalScopes()
                ->where('organization_id', $request->organization_id)
                ->where('is_active', true)
                ->orderBy('step_order')
                ->get();

            if ($stages->isEmpty()) {
                throw LeaveRequestException::noWorkflowConfigured($request->organization_id);
            }

            foreach ($stages as $stage) {
                LeaveRequestStep::create([
                    'leave_request_id' => $request->id,
                    'step_order' => $stage->step_order,
                    'stage' => $stage->stage_name,
                    'status' => StepStatus::PENDING,
                ]);
            }

            $firstStage = $stages->first();
            $request->status = LeaveStatus::SUBMITTED;
            $request->current_stage = $firstStage->stage_name;
            $request->save();

            // Notify relevant users (best-effort)
            $this->notifySubmitted($request);

            $request = $request->fresh(['steps']);

            // أي تقديم من المدرسة يعتمد مرحلة مدير المدرسة تلقائياً (توقيع إلكتروني)
            if ($firstStage->stage_name === 'school_principal') {
                $principalStep = $request->steps->firstWhere('stage', 'school_principal');

                if ($principalStep && $principalStep->status === StepStatus::PENDING) {
                    $approver = $this->resolveSchoolPrincipalApprover($request, $submittedBy);

                    return $this->approve(
                        $request,
                        $principalStep,
                        $approver,
                        'اعتماد إلكتروني من المدرسة عند التقديم'
                    );
                }
            }

            return $request;
        });

        $this->auditLeaveWorkflow($result->fresh(), $submittedBy, 'تقديم طلب إجازة للاعتماد');

        return $result;
    }

    /**
     * Prefer the school manager for the electronic signature; fall back to submitter.
     */
    private function resolveSchoolPrincipalApprover(LeaveRequest $request, User $submittedBy): User
    {
        $submittedBy->setOrganizationTeam();

        if ($submittedBy->hasRole('مدير مدرسة')) {
            return $submittedBy;
        }

        $organizationId = $request->organization_id;

        setPermissionsTeamId($organizationId);

        $manager = User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereHas('roles', function ($query) use ($organizationId) {
                $query->where('name', 'مدير مدرسة')
                    ->where('roles.organization_id', $organizationId);
            })
            ->first();

        setPermissionsTeamId($submittedBy->organization_id);

        return $manager ?? $submittedBy;
    }

    // -------------------------------------------------------------------------
    // Approve a Step
    // -------------------------------------------------------------------------

    /**
     * Record an approval decision on a workflow step.
     * Advances the request to the next stage or marks it fully approved.
     *
     * @throws LeaveRequestException
     */
    public function approve(
        LeaveRequest $request,
        LeaveRequestStep $step,
        User $actedBy,
        ?string $note = null
    ): LeaveRequest {
        $this->guardStepAction($request, $step, $actedBy);

        $fresh = DB::transaction(function () use ($request, $step, $actedBy, $note) {
            // Mark this step approved
            $step->update([
                'status' => StepStatus::APPROVED,
                'acted_by' => $actedBy->id,
                'acted_at' => now(),
                'note' => $note,
            ]);

            // Count approvals in this stage
            $stepsInStage = LeaveRequestStep::where('leave_request_id', $request->id)
                ->where('stage', $step->stage)
                ->get();

            $approvedCount = $stepsInStage->where('status', StepStatus::APPROVED)->count();
            $totalCount = $stepsInStage->count();

            // Get the approval rule for this stage
            $wfConfig = WorkflowConfiguration::where('organization_id', $request->organization_id)
                ->where('stage_name', $step->stage)
                ->where('is_active', true)
                ->first();

            $approvalRule = $wfConfig?->approval_rule ?? ApprovalRule::ANY;

            if (! $approvalRule->isStageApproved($approvedCount, $totalCount)) {
                // Not enough approvals yet — just save and wait
                return $request->fresh();
            }

            // Stage approved — skip any remaining pending steps in this stage
            LeaveRequestStep::where('leave_request_id', $request->id)
                ->where('stage', $step->stage)
                ->where('status', StepStatus::PENDING)
                ->update(['status' => StepStatus::SKIPPED->value, 'acted_at' => now()]);

            // Find next active stage
            $nextStage = WorkflowConfiguration::where('organization_id', $request->organization_id)
                ->where('is_active', true)
                ->where('step_order', '>', $wfConfig?->step_order ?? $step->step_order)
                ->orderBy('step_order')
                ->first();

            if ($nextStage) {
                // Advance to next stage
                LeaveRequestStep::firstOrCreate([
                    'leave_request_id' => $request->id,
                    'step_order' => $nextStage->step_order,
                ], [
                    'stage' => $nextStage->stage_name,
                    'status' => StepStatus::PENDING,
                ]);

                $request->status = LeaveStatus::IN_REVIEW;
                $request->current_stage = $nextStage->stage_name;
                $request->save();

                $this->notifyNextStage($request, $nextStage->stage_name);
            } else {
                // Final approval
                $request->status = LeaveStatus::APPROVED;
                $request->current_stage = null;
                $request->decided_by = $actedBy->id;
                $request->save();

                // Deduct balance only if leave type requires it
                if ($request->leaveType->deducts_balance) {
                    $reqFiscalYear = app(FiscalYearService::class)->fiscalYearFor($request->start_date);
                    $balance = $this->balanceService->getOrCreateBalance(
                        $request->employee,
                        $request->leaveType,
                        $reqFiscalYear
                    );
                    $this->balanceService->deduct($balance, (int) $request->days, $request, $actedBy);

                    $balance->refresh();
                    $request->balance_used = $balance->total_used;
                    $request->balance_remaining = $request->deduct_from_carried ? $balance->remaining_carried : $balance->remaining_entitled;
                    $request->save();
                }

                $this->notifyApproved($request);
            }

            return $request->fresh();
        });

        $this->auditLeaveWorkflow($fresh, $actedBy, "اعتماد مرحلة {$step->stage}", [
            'stage' => $step->stage,
            'note' => $note,
        ]);

        return $fresh;
    }

    // -------------------------------------------------------------------------
    // Reject
    // -------------------------------------------------------------------------

    /**
     * Reject the request. A reason is mandatory.
     *
     * @throws LeaveRequestException
     */
    public function reject(
        LeaveRequest $request,
        LeaveRequestStep $step,
        User $actedBy,
        string $reason
    ): LeaveRequest {
        if (empty(trim($reason))) {
            throw new LeaveRequestException('سبب الرفض مطلوب ولا يمكن أن يكون فارغاً.');
        }

        $this->guardStepAction($request, $step, $actedBy);

        $fresh = DB::transaction(function () use ($request, $step, $actedBy, $reason) {
            $step->update([
                'status' => StepStatus::REJECTED,
                'acted_by' => $actedBy->id,
                'acted_at' => now(),
                'note' => $reason,
            ]);

            // Skip all remaining pending steps
            LeaveRequestStep::where('leave_request_id', $request->id)
                ->where('status', StepStatus::PENDING)
                ->update(['status' => StepStatus::SKIPPED->value, 'acted_at' => now()]);

            $request->status = LeaveStatus::REJECTED;
            $request->rejection_reason = $reason;
            $request->current_stage = null;
            $request->decided_by = $actedBy->id;
            $request->save();

            $this->notifyRejected($request, $reason);

            return $request->fresh();
        });

        $this->auditLeaveWorkflow($fresh, $actedBy, 'رفض طلب إجازة', [
            'stage' => $step->stage,
            'reason' => $reason,
        ]);

        return $fresh;
    }

    // -------------------------------------------------------------------------
    // Return for Edits
    // -------------------------------------------------------------------------

    /**
     * Return the request to the employee for corrections.
     * Note: method named 'returnRequest' because 'return' is a PHP reserved word.
     *
     * @throws LeaveRequestException
     */
    public function returnRequest(
        LeaveRequest $request,
        LeaveRequestStep $step,
        User $actedBy,
        string $note
    ): LeaveRequest {
        $this->guardStepAction($request, $step, $actedBy);

        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new LeaveRequestException('ملاحظة الإعادة للتعديل مطلوبة ولا يمكن أن تكون فارغة.');
        }

        $fresh = DB::transaction(function () use ($request, $step, $actedBy, $note) {
            $step->update([
                'status' => StepStatus::RETURNED,
                'acted_by' => $actedBy->id,
                'acted_at' => now(),
                'note' => $note,
            ]);

            LeaveRequestStep::where('leave_request_id', $request->id)
                ->where('status', StepStatus::PENDING)
                ->update(['status' => StepStatus::SKIPPED->value, 'acted_at' => now()]);

            $request->status = LeaveStatus::RETURNED;
            $request->current_stage = null;
            $request->save();

            $this->notifyReturned($request, $note);

            return $request->fresh();
        });

        $this->auditLeaveWorkflow($fresh, $actedBy, 'إعادة طلب إجازة للتعديل', [
            'stage' => $step->stage,
            'note' => $note,
        ]);

        return $fresh;
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    /**
     * Cancel a request. If it was previously approved, refunds the balance.
     *
     * @throws LeaveRequestException
     */
    public function cancel(LeaveRequest $request, User $cancelledBy): LeaveRequest
    {
        if (! $request->status->canBeCancelled()) {
            throw LeaveRequestException::invalidTransition($request->status, LeaveStatus::CANCELLED);
        }

        $fresh = DB::transaction(function () use ($request, $cancelledBy) {
            $wasApproved = $request->status === LeaveStatus::APPROVED;

            LeaveRequestStep::where('leave_request_id', $request->id)
                ->where('status', StepStatus::PENDING)
                ->update(['status' => StepStatus::SKIPPED->value, 'acted_at' => now()]);

            $request->status = LeaveStatus::CANCELLED;
            $request->current_stage = null;
            $request->save();

            if ($wasApproved && $request->leaveType->deducts_balance) {
                $balance = $this->balanceService->getOrCreateBalance(
                    $request->employee,
                    $request->leaveType,
                    now()->year
                );
                $this->balanceService->refund($balance, (int) $request->days, $request, $cancelledBy);

                $balance->refresh();
                $request->balance_used = $balance->used;
                $request->balance_remaining = $balance->remaining;
                $request->save();
            }

            return $request->fresh();
        });

        $this->auditLeaveWorkflow($fresh, $cancelledBy, 'إلغاء طلب إجازة');

        return $fresh;
    }

    // -------------------------------------------------------------------------
    // Restore
    // -------------------------------------------------------------------------

    /**
     * Restore a soft-deleted leave request and reset its status to DRAFT.
     */
    public function restore(LeaveRequest $request, User $restoredBy): LeaveRequest
    {
        $fresh = DB::transaction(function () use ($request) {
            $request->restore();

            if ($request->status === LeaveStatus::CANCELLED) {
                $request->forceFill([
                    'status' => LeaveStatus::DRAFT,
                    'current_stage' => null,
                ])->save();
            }

            // Sync any deletion request
            DeletionRequest::where('model_type', 'leave_request')
                ->where('model_id', $request->id)
                ->where('status', 'approved')
                ->update([
                    'status' => 'restored',
                    'admin_notes' => 'تمت استعادة طلب الإجازة من الحذف العادي',
                ]);

            return $request->fresh();
        });

        $this->auditLeaveWorkflow($fresh, $restoredBy, 'استعادة طلب إجازة محذوف');

        return $fresh;
    }

    private function auditLeaveWorkflow(LeaveRequest $request, User $actedBy, string $description, array $extra = []): void
    {
        $this->auditLog->recordWorkflow(
            $description,
            $request,
            array_merge([
                'leave_request_id' => $request->id,
                'number' => $request->number,
                'status' => $request->status->value,
                'current_stage' => $request->current_stage,
                'organization_id' => $request->organization_id,
            ], $extra),
            $actedBy,
        );
    }

    // -------------------------------------------------------------------------
    // Guards
    // -------------------------------------------------------------------------

    private function guardStepAction(LeaveRequest $request, LeaveRequestStep $step, User $actedBy): void
    {
        $validStatuses = [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW];

        if (! in_array($request->status, $validStatuses)) {
            throw LeaveRequestException::invalidTransition(
                $request->status,
                LeaveStatus::IN_REVIEW,
                'الطلب لا يقبل إجراءات الاعتماد في حالته الحالية.'
            );
        }

        if ($step->status !== StepStatus::PENDING) {
            throw new LeaveRequestException('هذه المرحلة تمت معالجتها مسبقاً ولا يمكن تعديلها.');
        }

        if ($step->stage !== $request->current_stage) {
            throw new LeaveRequestException('هذه المرحلة ليست المرحلة النشطة حالياً.');
        }

        // التحقق من أن المستخدم يملك الدور المطلوب لهذه المرحلة
        $stageRoleMap = [
            'leaves_officer' => 'مسؤول الإجازات',
            'hr_affairs' => 'شؤون عاملين',
            'admin_manager' => 'مدير الإدارة',
        ];

        if (isset($stageRoleMap[$step->stage])) {
            $actedBy->setOrganizationTeam();
            $requiredRole = $stageRoleMap[$step->stage];
            if (! $actedBy->hasRole($requiredRole)) {
                throw new LeaveRequestException(
                    "ليس لديك صلاحية اتخاذ إجراء في مرحلة «{$requiredRole}»."
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Notifications (best-effort, never break the main flow)
    // -------------------------------------------------------------------------

    private function notifySubmitted(LeaveRequest $request): void
    {
        try {
            $users = $this->getUsersForStage($request, $request->current_stage);
            foreach ($users as $user) {
                $user->notify(new LeaveRequestNotification(
                    $request,
                    'submitted',
                    "طلب إجازة جديد بانتظار مراجعتك — رقم {$request->number}"
                ));
            }
        } catch (\Throwable) {
            // Notifications never break the workflow
        }
    }

    private function notifyNextStage(LeaveRequest $request, string $stageName): void
    {
        try {
            $users = $this->getUsersForStage($request, $stageName);
            foreach ($users as $user) {
                $user->notify(new LeaveRequestNotification(
                    $request,
                    'submitted',
                    "طلب إجازة في انتظار رأيكم — رقم {$request->number}"
                ));
            }
        } catch (\Throwable) {
        }
    }

    private function notifyApproved(LeaveRequest $request): void
    {
        try {
            $request->createdBy?->notify(new LeaveRequestNotification(
                $request,
                'approved',
                "تمت الموافقة على طلب الإجازة رقم {$request->number}"
            ));
        } catch (\Throwable) {
        }
    }

    private function notifyRejected(LeaveRequest $request, string $reason): void
    {
        try {
            $request->createdBy?->notify(new LeaveRequestNotification(
                $request,
                'rejected',
                "تم رفض طلب الإجازة رقم {$request->number}. السبب: {$reason}"
            ));
        } catch (\Throwable) {
        }
    }

    private function notifyReturned(LeaveRequest $request, string $note): void
    {
        try {
            $request->createdBy?->notify(new LeaveRequestNotification(
                $request,
                'returned',
                "أُعيد طلب الإجازة رقم {$request->number} للتعديل. ملاحظة: {$note}"
            ));
        } catch (\Throwable) {
        }
    }

    /**
     * Find users who should receive notifications for a given stage.
     * Looks up users with the required_role defined in WorkflowConfiguration.
     */
    private function getUsersForStage(LeaveRequest $request, ?string $stageName): iterable
    {
        if (! $stageName) {
            return [];
        }

        $wfConfig = WorkflowConfiguration::where('organization_id', $request->organization_id)
            ->where('stage_name', $stageName)
            ->where('is_active', true)
            ->first();

        if (! $wfConfig || ! $wfConfig->required_role) {
            return [];
        }

        setPermissionsTeamId($request->organization_id);

        $users = User::where('organization_id', $request->organization_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn ($u) => $u->hasRole($wfConfig->required_role));

        setPermissionsTeamId(null);

        return $users;
    }
}
