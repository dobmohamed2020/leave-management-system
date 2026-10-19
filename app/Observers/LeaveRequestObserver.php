<?php

namespace App\Observers;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles leave request lifecycle events beyond what LogsActivity covers:
 *  - Generates a unique human-readable request number on creation.
 *  - Records submitted_at / decided_at timestamps automatically.
 *  - Logs status transitions to the Laravel log channel for quick ops debugging.
 */
class LeaveRequestObserver
{
    /**
     * Generate a sequential, human-readable request number before the first save.
     * Format: LV-{YEAR}-{6-digit-sequence}  e.g. LV-2026-000123
     */
    public function creating(LeaveRequest $request): void
    {
        if (empty($request->number)) {
            $request->number = $this->generateNumber();
        }

        if (empty($request->verification_token)) {
            $request->verification_token = (string) Str::uuid();
        }
    }

    /**
     * Stamp timestamps when status transitions happen.
     */
    public function updating(LeaveRequest $request): void
    {
        if (! $request->isDirty('status')) {
            return;
        }

        $newStatus = $request->status;

        // Mark the moment the request leaves draft
        if ($newStatus === LeaveStatus::SUBMITTED && empty($request->submitted_at)) {
            $request->submitted_at = now();
        }

        // Mark the final decision moment
        if (in_array($newStatus, [LeaveStatus::APPROVED, LeaveStatus::REJECTED])) {
            $request->decided_at = now();
        }
    }

    /**
     * Log status changes for ops visibility.
     */
    public function updated(LeaveRequest $request): void
    {
        if (! $request->wasChanged('status')) {
            return;
        }

        $raw = $request->getOriginal('status');
        $old = ($raw instanceof LeaveStatus ? $raw : LeaveStatus::from($raw))->label();
        $new = $request->status->label();

        Log::info("[LeaveRequest] #{$request->number} | {$old} → {$new}", [
            'id' => $request->id,
            'employee' => $request->employee_id,
            'decided_by' => $request->decided_by,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function generateNumber(): string
    {
        $year = now()->year;

        // Atomic sequence using DB lock to avoid race conditions.
        // CAST AS INTEGER is SQLite-only; MySQL needs UNSIGNED/SIGNED.
        return DB::transaction(function () use ($year) {
            $castExpr = match (DB::getDriverName()) {
                'mysql', 'mariadb' => 'CAST(RIGHT(`number`, 6) AS UNSIGNED)',
                'pgsql' => 'CAST(RIGHT(number, 6) AS INTEGER)',
                default => 'CAST(SUBSTR(number, -6) AS INTEGER)',
            };

            $last = LeaveRequest::withoutGlobalScopes()
                ->whereYear('created_at', $year)
                ->where('number', 'like', "LV-{$year}-%")
                ->lockForUpdate()
                ->max(DB::raw($castExpr));

            $sequence = ((int) ($last ?? 0)) + 1;

            return sprintf('LV-%d-%06d', $year, $sequence);
        });
    }
}
