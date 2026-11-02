<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\WorkspaceRead;
use Throwable;

class SuperAdminDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()?->normalizedRole() === 'super_admin', 403);
        $started = hrtime(true);
        try {
            $probe = hrtime(true);
            DB::select('SELECT 1');
            $databaseMs = (hrtime(true) - $probe) / 1e6;
            $users = \App\Services\WorkspaceAccess::accounts($request->user());
            $total = (clone $users)->count();
            $active = (clone $users)->whereRaw("LOWER(COALESCE(status, 'Active')) = 'active'")->count();
            // Seven completed calendar days, including days without recorded events.
            $end = now()->startOfDay();
            $start = $end->copy()->subDays(7);
            $counts = collect();
            $days = [];
            for ($date = $start->copy(); $date->lt($end); $date->addDay()) {
                $days[] = ['date' => $date->toDateString(), 'events' => (int) ($counts[$date->toDateString()] ?? 0)];
            }

            return response()->json([
                'total_users' => $total, 'active_users' => $active, 'inactive_users' => $total - $active,
                'average_daily_events' => round(array_sum(array_column($days, 'events')) / 7, 2),
                'activity_days' => $days,
                'recent_activity' => [],
                'application_status' => 'Operational', 'database_status' => 'Connected',
                'database_response_ms' => $databaseMs,
                'generation_ms' => (hrtime(true) - $started) / 1e6,
                'measured_at' => now()->toIso8601String(),
            ])->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Unable to retrieve system performance information.'], 503);
        }
    }
}
