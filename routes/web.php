<?php

use App\Exports\LeaveRequestsExport;
use App\Http\Controllers\LeaveVerificationController;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Organization;
use App\Services\CredentialsPdfService;
use App\Services\EmployeeReportService;
use App\Services\LeaveRequestPdfService;
use App\Services\ReportPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Maatwebsite\Excel\Facades\Excel;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/login', function () {
    return redirect('/admin/login');
})->name('login');

// Render / load balancer health check
Route::get('/healthz', fn () => response('ok', 200));

// Public verification (rate-limited; token-based QR links)
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/verify/{token}', [LeaveVerificationController::class, 'showByToken'])
        ->where('token', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
        ->name('leave.verify');

    Route::get('/verify/legacy/{number}', [LeaveVerificationController::class, 'showLegacyByNumber'])
        ->name('leave.verify.legacy');
});

// Authenticated PDF download route
Route::get('/leave-pdf/{number}', function (string $number) {
    $leaveRequest = LeaveRequest::withoutGlobalScopes()
        ->where('number', $number)
        ->firstOrFail();

    // Enforce per-record authorization — only users with 'view' permission on this record
    Gate::authorize('view', $leaveRequest);

    app()->setLocale('ar');

    $service = app(LeaveRequestPdfService::class);
    $content = $service->generate($leaveRequest);
    $filename = $service->downloadFilename($leaveRequest);

    return response()->streamDownload(
        fn () => print ($content),
        $filename,
        [
            'Content-Type' => 'application/pdf',
            'Content-Language' => 'ar',
        ]
    );
})->middleware('auth')->name('leave.pdf.download');

// Authenticated print-friendly HTML page (native Arabic rendering)
Route::get('/leave-print/{number}', function (string $number) {
    $leaveRequest = LeaveRequest::withoutGlobalScopes()
        ->where('number', $number)
        ->firstOrFail();

    Gate::authorize('view', $leaveRequest);

    app()->setLocale('ar');

    $data = app(LeaveRequestPdfService::class)->viewData($leaveRequest);

    return response()
        ->view('print.leave-request', $data)
        ->header('Content-Language', 'ar');
})->middleware('auth')->name('leave.pdf.print');

// Authenticated comprehensive employee report print route
Route::get('/employee-report/{id}', function (int $id) {
    $user = auth()->user();
    if (! $user) {
        abort(403);
    }
    $user->setOrganizationTeam();

    $employee = Employee::withoutGlobalScopes()->findOrFail($id);

    // Enforce org scope authorization
    if ($user->organization) {
        $allowedIds = $user->organization->subtreeIds();
        if (! in_array((int) $employee->organization_id, $allowedIds)) {
            abort(403, 'غير مصرح لك بعرض بيانات هذا الموظف.');
        }
    }

    app()->setLocale('ar');

    $data = app(EmployeeReportService::class)->getReportData($employee);

    return response()
        ->view('print.employee-report', $data)
        ->header('Content-Language', 'ar');
})->middleware('auth')->name('employee.report.print');

// Authenticated Excel export route
Route::get('/leave-export', function (Request $request) {
    $user = auth()->user();
    $user->setOrganizationTeam();
    Gate::authorize('export_reports');

    $filters = $request->only(['status', 'organization_id', 'employee_name', 'leave_type_id']);

    if (! empty($filters['organization_id']) && $user->organization) {
        $allowedIds = $user->organization->subtreeIds();
        if (! in_array((int) $filters['organization_id'], $allowedIds, true)) {
            abort(403, 'غير مصرح لك بتصدير بيانات هذه الجهة.');
        }
    } elseif ($user->organization) {
        $filters['organization_id'] = $user->organization_id;
    } elseif (! $user->can('manage_organization')) {
        abort(403, 'غير مصرح لك بتصدير البيانات.');
    }

    return Excel::download(
        new LeaveRequestsExport($filters),
        'leave-requests.xlsx'
    );
})->middleware('auth')->name('leave.export');

// Authenticated report PDF download route
Route::get('/report-pdf', function (Request $request) {
    $user = auth()->user();
    $user->setOrganizationTeam();
    Gate::authorize('view_reports');

    $filters = $request->only(['status', 'organization_id', 'leave_type_id', 'from', 'to']);

    // Enforce org-scope authorization: if an organization_id is supplied it must belong
    // to the authenticated user's own org or one of its descendants.
    if (! empty($filters['organization_id'])) {
        $user = auth()->user();
        $userOrg = $user->organization;

        if ($userOrg) {
            // Build the set of org IDs the user is allowed to see (own org + subtree).
            $allowedIds = Organization::withoutGlobalScopes()
                ->where('path', 'like', $userOrg->path.'%')
                ->pluck('id')
                ->all();

            if (! in_array((int) $filters['organization_id'], $allowedIds)) {
                abort(403, 'غير مصرح لك بتصدير بيانات هذه الجهة.');
            }
        } else {
            // User has no org — deny scoped access entirely.
            abort(403, 'غير مصرح لك بتصدير بيانات هذه الجهة.');
        }
    }

    $content = app(ReportPdfService::class)->generateSummary($filters);

    return response()->streamDownload(
        fn () => print ($content),
        'report.pdf',
        ['Content-Type' => 'application/pdf']
    );
})->middleware('auth')->name('report.pdf.download');

// Authenticated credentials PDF download / view route (Admin Manager & Super Admin only)
Route::get('/credentials-pdf', function () {
    $user = auth()->user();

    if (! $user) {
        abort(403, 'غير مصرح لك بالوصول.');
    }

    $user->setOrganizationTeam();

    $canDownload = $user->email === 'admin@rtltec.com'
        || $user->hasRole(['مدير المديرية', 'مدير الإدارة'])
        || $user->can('manage_users');

    if (! $canDownload) {
        abort(403, 'غير مصرح لك بتحميل هذا الملف. متاح فقط لمدير الإدارة ومدير النظام.');
    }

    $content = app(CredentialsPdfService::class)->generate();

    return response($content, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="armant-credentials.pdf"',
    ]);
})->middleware('auth')->name('credentials.pdf');
