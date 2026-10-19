<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Enums\StepStatus;
use App\Models\Employee;
use App\Models\EntitlementGrade;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Organization;
use App\Support\ArabicPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Picqer\Barcode\BarcodeGeneratorPNG;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class LeaveRequestPdfService
{
    /**
     * Build the shared view data for PDF / print templates.
     *
     * @return array<string, mixed>
     */
    public function viewData(LeaveRequest $request): array
    {
        $request->loadMissing([
            'employee.entitlementGrade',
            'leaveType',
            'steps.actedBy',
        ]);

        // Load organization + parents outside OrganizationScope (school users can't see parent admin).
        $organization = Organization::withoutGlobalScopes()->find($request->organization_id);
        $hierarchy = $this->resolveHierarchy($organization);

        // Substitute may be soft-deleted — still show the name on the form.
        $substitute = $request->substitute_employee_id
            ? Employee::withoutGlobalScopes()->withTrashed()->find($request->substitute_employee_id)
            : null;

        $leaveTypeName = $request->leaveType?->name ?? 'إجازة';
        $leaveTypeShort = trim(preg_replace('/^إجازة\s*/u', '', $leaveTypeName) ?: $leaveTypeName);

        $employee = $request->employee;
        // Prefer entitlement grade (معلم / معلم أول / …) over free-text employee.grade
        $gradeLabel = $employee?->entitlementGrade?->name
            ?: (EntitlementGrade::findByCode($employee?->entitlement_grade)?->name)
            ?: $employee?->grade
            ?: null;

        // ملحوظة العارضة تظهر فقط مع الإجازة الاعتيادية لمدة يوم أو يومين
        $showCasualNote = ($request->leaveType?->isRegular() ?? false)
            && (float) $request->days <= 2;

        // Live balance from DB (more accurate than the snapshot stored on the request)
        $liveBalance = null;
        if ($employee && $request->leaveType) {
            $liveBalance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $request->leaveType->id)
                ->where('year', $request->start_date?->year ?? now()->year)
                ->first();
        }

        $liveEntitled = $liveBalance?->entitled ?? $request->balance_entitled;
        $liveUsed = $liveBalance?->used ?? $request->balance_used;
        $liveRemaining = $liveBalance
            ? max(0, (int) $liveBalance->entitled + (int) $liveBalance->carried_over - (int) $liveBalance->used)
            : $request->balance_remaining;

        return [
            'leaveRequest' => $request,
            'directorate' => $hierarchy['directorate'],
            'administration' => $hierarchy['administration'],
            'school' => $hierarchy['school'],
            'barcode' => $this->generateBarcode($request->number),
            'qrCode' => $this->generateQrCode($request),
            'daysInWords' => $this->daysToArabicWords((int) $request->days),
            'approvalRows' => $this->buildApprovalRows($request),
            'printedAt' => now(),
            'leaveTypeShort' => $leaveTypeShort,
            'employeeGrade' => $gradeLabel,
            'substituteName' => $substitute?->full_name,
            'showCasualNote' => $showCasualNote,
            'liveEntitled' => $liveEntitled,
            'liveUsed' => $liveUsed,
            'liveRemaining' => $liveRemaining,
        ];
    }

    /**
     * Generate a PDF binary string for the given leave request.
     */
    public function generate(LeaveRequest $request): string
    {
        // Always render Arabic UI strings regardless of current app locale.
        $previousLocale = app()->getLocale();
        app()->setLocale('ar');

        try {
            $html = view('pdf.leave-request', $this->viewData($request))->render();
            $html = ArabicPdf::shapeHtml($html);

            return Pdf::loadHTML($html)
                ->setPaper('a4', 'portrait')
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', true)
                ->setOption('defaultFont', 'DejaVu Sans')
                ->output();
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * Arabic download filename: طلب-إجازة-{اسم الموظف}-{رقم الطلب}.pdf
     */
    public function downloadFilename(LeaveRequest $request): string
    {
        $request->loadMissing('employee');

        $name = trim((string) ($request->employee?->full_name ?? 'موظف'));
        $name = preg_replace('/\s+/u', '-', $name) ?? $name;
        $name = preg_replace('/[\\\\\\/:*?"<>|]+/u', '', $name) ?? $name;

        $number = str_replace(['/', '\\'], '-', (string) $request->number);

        return "طلب-إجازة-{$name}-{$number}.pdf";
    }

    /**
     * @return array{directorate: ?Organization, administration: ?Organization, school: ?Organization}
     */
    private function resolveHierarchy(?Organization $organization): array
    {
        $school = null;
        $administration = null;
        $directorate = null;

        $current = $organization
            ? Organization::withoutGlobalScopes()->find($organization->id)
            : null;

        while ($current) {
            match ($current->type) {
                OrganizationType::SCHOOL => $school = $current,
                OrganizationType::ADMINISTRATION => $administration = $current,
                OrganizationType::DIRECTORATE => $directorate = $current,
                default => null,
            };

            $current = $current->parent_id
                ? Organization::withoutGlobalScopes()->find($current->parent_id)
                : null;
        }

        return compact('directorate', 'administration', 'school');
    }

    private function generateBarcode(string $number): string
    {
        try {
            // Code128 accepts ASCII only — map Arabic prefix to EG for the barcode payload.
            $barcodeValue = preg_replace('/^إج-/', 'EG-', $number) ?? $number;

            $generator = new BarcodeGeneratorPNG;
            $png = $generator->getBarcode($barcodeValue, $generator::TYPE_CODE_128, 2, 50);

            return 'data:image/png;base64,'.base64_encode($png);
        } catch (\Throwable $e) {
            Log::warning('[LeaveRequestPdfService] Barcode generation failed.', [
                'number' => $number,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function generateQrCode(LeaveRequest $request): string
    {
        $token = $request->verification_token;

        if (empty($token)) {
            return '';
        }

        try {
            return QrCode::format('svg')
                ->size(70)
                ->margin(0)
                ->generate(route('leave.verify', ['token' => $token]));
        } catch (\Throwable $e) {
            Log::warning('[LeaveRequestPdfService] QR code generation failed.', [
                'number' => $request->number,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * @return list<array{label: string, name: string, date: string, note: string, signed: bool, signature: string}>
     */
    private function buildApprovalRows(LeaveRequest $request): array
    {
        $defaults = [
            'school_principal' => 'مدير المدرسة (توقيع إلكتروني)',
            'leaves_officer' => 'مسؤول الإجازات',
            'hr_affairs' => 'شؤون عاملين',
            'admin_manager' => 'مدير الإدارة',
        ];

        $rows = [];
        $steps = $request->steps->keyBy('stage');

        foreach ($defaults as $stage => $label) {
            $step = $steps->get($stage);
            $signed = $step?->status === StepStatus::APPROVED;
            $actorName = $step?->actedBy?->name ?? '';

            $rows[] = [
                'label' => $label,
                'name' => $actorName,
                'date' => $step?->acted_at?->format('Y/m/d') ?? '',
                'note' => $step?->note ?? '',
                'signed' => $signed,
                'signature' => $signed ? $actorName : '',
            ];
        }

        return $rows;
    }

    /**
     * Convert an integer number of days to Arabic words.
     */
    private function daysToArabicWords(int $days): string
    {
        $standalone = [
            '', 'يوم واحد', 'يومان', 'ثلاثة أيام', 'أربعة أيام', 'خمسة أيام',
            'ستة أيام', 'سبعة أيام', 'ثمانية أيام', 'تسعة أيام', 'عشرة أيام',
            'أحد عشر يوماً', 'اثنا عشر يوماً', 'ثلاثة عشر يوماً', 'أربعة عشر يوماً',
            'خمسة عشر يوماً', 'ستة عشر يوماً', 'سبعة عشر يوماً', 'ثمانية عشر يوماً',
            'تسعة عشر يوماً',
        ];

        $units = [
            '', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة',
            'ستة', 'سبعة', 'ثمانية', 'تسعة',
        ];

        $tens = [
            '', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون',
            'ستون', 'سبعون', 'ثمانون', 'تسعون',
        ];

        if ($days <= 0) {
            return 'صفر أيام';
        }

        if ($days < 20) {
            return $standalone[$days];
        }

        if ($days < 100) {
            $ten = (int) ($days / 10);
            $rest = $days % 10;

            if ($rest === 0) {
                return $tens[$ten].' يوماً';
            }

            return $units[$rest].' و'.$tens[$ten].' يوماً';
        }

        return "{$days} يوماً";
    }
}
