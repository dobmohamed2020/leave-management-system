<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DashboardStatsService $stats,
    ) {}

    // GET /api/dashboard/stats
    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $user = $request->user()->load('organization');
        $org  = $user->organization;

        if (! $org) {
            return $this->error('المستخدم غير مرتبط بمؤسسة.', 422);
        }

        $overdueCount = $this->stats->overdueCount($org);

        if ($org->isSchool()) {
            $statusCounts = $this->stats->schoolStatusCounts($org);
            return $this->success([
                'level'             => 'school',
                'status_counts'     => $statusCounts,
                'on_leave_today_count' => $this->stats->onLeaveToday($org)->count(),
                'pending_count'     => $this->stats->pendingRequests($org)->count(),
                'overdue_count'     => $overdueCount,
            ]);
        }

        if ($org->isAdministration()) {
            $schoolStats = $this->stats->administrationSchoolStats($org);
            $topTakers   = $this->stats->topLeaveTakers($org, 5);
            return $this->success([
                'level'            => 'administration',
                'school_stats'     => $schoolStats,
                'top_takers'       => $topTakers->map(fn($e) => [
                    'name'        => $e->full_name,
                    'school'      => $e->school_name,
                    'total_days'  => $e->total_days,
                ]),
                'avg_response_time' => $this->stats->avgResponseTime($org),
                'overdue_count'    => $overdueCount,
            ]);
        }

        // Directorate
        $adminStats = $this->stats->directorateAdminStats($org);
        $monthly    = $this->stats->monthlyRequestTrend($org, now()->year);
        $yearly     = $this->stats->yearlyComparison($org, 3);

        return $this->success([
            'level'         => 'directorate',
            'admin_stats'   => $adminStats,
            'monthly_trend' => $monthly,
            'yearly_comparison' => $yearly,
            'overdue_count' => $overdueCount,
        ]);
    }
}
