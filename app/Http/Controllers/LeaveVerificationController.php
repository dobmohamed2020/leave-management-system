<?php

namespace App\Http\Controllers;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class LeaveVerificationController extends Controller
{
    /**
     * Public verification by unguessable token (QR on approved leave PDFs).
     */
    public function showByToken(string $token): Response
    {
        $request = LeaveRequest::withoutGlobalScopes()
            ->where('verification_token', $token)
            ->with(['employee', 'leaveType', 'organization'])
            ->first();

        if (! $request || $request->status !== LeaveStatus::APPROVED) {
            abort(404);
        }

        return response()->view('public.leave-verify', compact('request'));
    }

    /**
     * Legacy URLs that used sequential request numbers — redirect to token URL when possible.
     */
    public function showLegacyByNumber(string $number): RedirectResponse|Response
    {
        $request = LeaveRequest::withoutGlobalScopes()
            ->where('number', $number)
            ->first();

        if (! $request || $request->status !== LeaveStatus::APPROVED || empty($request->verification_token)) {
            abort(404);
        }

        return redirect()->route('leave.verify', ['token' => $request->verification_token]);
    }
}
