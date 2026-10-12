<?php

use App\Http\Controllers\LeaveVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// Render / load balancer health check
Route::get('/healthz', fn () => response('ok', 200));

// Public verification page (no auth required)
Route::get('/verify/{number}', [LeaveVerificationController::class, 'show'])
    ->name('leave.verify');

// Authenticated PDF download route
Route::get('/leave-pdf/{number}', function (string $number) {
    $leaveRequest = \App\Models\LeaveRequest::withoutGlobalScopes()
        ->where('number', $number)
        ->firstOrFail();

    // Enforce per-record authorization — only users with 'view' permission on this record
    \Illuminate\Support\Facades\Gate::authorize('view', $leaveRequest);

    app()->setLocale('ar');

    $service = app(\App\Services\LeaveRequestPdfService::class);
    $content = $service->generate($leaveRequest);
    $filename = $service->downloadFilename($leaveRequest);

    return response()->streamDownload(
        fn () => print($content),
        $filename,
        [
            'Content-Type' => 'application/pdf',
            'Content-Language' => 'ar',
        ]
    );
})->middleware('auth')->name('leave.pdf.download');

// Authenticated print-friendly HTML page (native Arabic rendering)
Route::get('/leave-print/{number}', function (string $number) {
    $leaveRequest = \App\Models\LeaveRequest::withoutGlobalScopes()
        ->where('number', $number)
        ->firstOrFail();

    \Illuminate\Support\Facades\Gate::authorize('view', $leaveRequest);

    app()->setLocale('ar');

    $data = app(\App\Services\LeaveRequestPdfService::class)->viewData($leaveRequest);

    return response()
        ->view('print.leave-request', $data)
        ->header('Content-Language', 'ar');
})->middleware('auth')->name('leave.pdf.print');

// Authenticated Excel export route
Route::get('/leave-export', function (\Illuminate\Http\Request $request) {
    $filters = $request->only(['status', 'organization_id', 'employee_name', 'leave_type_id']);
    return \Maatwebsite\Excel\Facades\Excel::download(
        new \App\Exports\LeaveRequestsExport($filters),
        'leave-requests.xlsx'
    );
})->middleware('auth')->name('leave.export');

// Authenticated report PDF download route
Route::get('/report-pdf', function (\Illuminate\Http\Request $request) {
    $filters = $request->only(['status', 'organization_id', 'leave_type_id', 'from', 'to']);

    // Enforce org-scope authorization: if an organization_id is supplied it must belong
    // to the authenticated user's own org or one of its descendants.
    if (! empty($filters['organization_id'])) {
        $user = auth()->user();
        $userOrg = $user->organization;

        if ($userOrg) {
            // Build the set of org IDs the user is allowed to see (own org + subtree).
            $allowedIds = \App\Models\Organization::withoutGlobalScopes()
                ->where('path', 'like', $userOrg->path . '%')
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

    $content = app(\App\Services\ReportPdfService::class)->generateSummary($filters);
    return response()->streamDownload(
        fn () => print($content),
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

    $content = app(\App\Services\CredentialsPdfService::class)->generate();

    return response($content, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'inline; filename="armant-credentials.pdf"',
    ]);
})->middleware('auth')->name('credentials.pdf');

