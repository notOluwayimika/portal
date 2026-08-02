<?php

namespace App\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * "Did the worker run?"
 *
 * WHY THIS SHIPS IN v1 RATHER THAN WITH THE REST OF THE OBSERVABILITY. There is no
 * Horizon here — it needs Redis and a supervised process, and this host has
 * neither — and the worker is a cron-invoked `queue:work` rather than a daemon.
 * So the single most likely failure in this whole subsystem is the silent one:
 * cron stops, nothing throws, no error is logged, and notifications simply stop
 * arriving while every page still returns 200. Nothing else in the system would
 * notice. This page is the thing that notices.
 *
 * A delivery still `pending` past the configured threshold IS the alarm. The
 * threshold is minutes rather than the hour a daemon would warrant, because the
 * invocation is supposed to be every minute.
 *
 * PERMISSION REUSE, STATED RATHER THAN HIDDEN: gated on `activity_log.view_system`
 * — the existing system-observability grant, held by exactly the operators who
 * would act on this. A dedicated `notification.queue.view` would be more precise
 * and means regenerating three RBAC oracles plus the grants baseline for one
 * read-only page; that is deferred to v2, when the preferences surface adds
 * permissions anyway and the oracle churn is paid once.
 */
class NotificationQueueHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $stuckAfter = now()->subMinutes((int) config('notifications.health.stuck_after_minutes'));

        // The query builder, not Eloquent: this is an aggregate, and hydrating
        // models to read a COUNT alias means casting `status`/`channel` into enums
        // only to unwrap them again — and `total` is not a model attribute at all.
        $byStatus = DB::table('notification_deliveries')
            ->select('status', 'channel', DB::raw('COUNT(*) as total'))
            ->groupBy('status', 'channel')
            ->get()
            ->groupBy('status')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->channel => (int) $r->total]));

        $pending = NotificationDelivery::query()->where('status', DeliveryStatus::PENDING->value);

        $stuck = (clone $pending)->where('queued_at', '<', $stuckAfter)->count();
        $oldest = (clone $pending)->min('queued_at');

        // Depth of the isolated queue table itself, not just of delivery rows —
        // a fan-out job that never ran leaves NO pending delivery at all, so
        // counting deliveries alone would report a healthy zero.
        $queueDepth = DB::table('notification_jobs')->count();
        $reserved = DB::table('notification_jobs')->whereNotNull('reserved_at')->count();

        return response()->json([
            'healthy' => $stuck === 0,
            'stuck_after_minutes' => (int) config('notifications.health.stuck_after_minutes'),
            'stuck_deliveries' => $stuck,
            'oldest_pending_at' => $oldest,
            'queue_depth' => $queueDepth,
            'queue_reserved' => $reserved,
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'deliveries_by_status' => $byStatus,
        ]);
    }
}
