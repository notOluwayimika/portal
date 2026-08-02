<?php

namespace App\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\Http\Resources\NotificationFeedResource;
use App\Notifications\Models\NotificationRecipient;
use App\Notifications\Services\PayloadHydrator;
use App\Support\ActiveSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The in-app feed.
 *
 * EVERY QUERY IS BOUND TO (this user, the ACTIVE school). Both halves matter:
 * `school_user` is a pivot, so one human can be staff at one school and a parent
 * at another, and a feed keyed by user alone would hand them the wrong tenant's
 * notifications. The school comes from ActiveSchool, never from
 * `users.school_id` (Constitution 13 / ADR 0042).
 *
 * NO POLICY, AND DELIBERATELY SO. Authorization here is not "may this user read
 * notifications?" — it is "these rows ARE this user's", which is expressed by the
 * WHERE clause rather than checked after the fact. There is no route that can
 * name another user's row, so there is nothing for a policy to guard.
 * NotificationFeedAuthzTest proves the ownership scoping bites.
 */
class NotificationFeedController extends Controller
{
    public function __construct(private readonly PayloadHydrator $hydrator) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request)->with('notification');

        if ($request->query('filter') === 'unread') {
            $query->whereNull('read_at');
        }

        // Cursor, not offset: the feed grows at the head, and an offset page 2
        // silently skips rows whenever something arrives mid-browse.
        $page = $query->orderByDesc('id')
            ->cursorPaginate((int) config('notifications.feed.page_size'));

        // ONE resolve pass for the whole page. Payloads hold ids, so rendering
        // row-by-row would be an N+1 on every feed load.
        $this->hydrator->hydrate(collect($page->items()));

        return response()->json([
            'data' => NotificationFeedResource::collection($page->items()),
            'next_cursor' => $page->nextCursor()?->encode(),
            'unread_count' => $this->unreadQuery($request)->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        // The polled endpoint, and the reason for the composite index on
        // (notifiable, school, read_at, id) — this is served without touching a
        // row of the notifications table.
        return response()->json(['unread_count' => $this->unreadQuery($request)->count()]);
    }

    public function markRead(Request $request, string $uuid): JsonResponse
    {
        $recipient = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();

        // Idempotent: re-marking keeps the ORIGINAL timestamp, so "when did they
        // read it?" survives a double-click.
        if ($recipient->read_at === null) {
            $recipient->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['unread_count' => $this->unreadQuery($request)->count()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        // BOUNDED by the newest row the client has actually seen. An unbounded
        // UPDATE would also clear notifications that arrived after the page
        // rendered — marking read something the user has never been shown.
        $before = $request->integer('before_id') ?: null;

        $query = $this->scoped($request)->whereNull('read_at');

        if ($before !== null) {
            $query->where('id', '<=', $before);
        }

        $query->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['unread_count' => $this->unreadQuery($request)->count()]);
    }

    /**
     * Clear the bell badge without marking anything read.
     *
     * Seen and read are different events: the badge should stop nagging once the
     * bell is opened, while each item stays unread until it is actually looked at.
     */
    public function markSeen(Request $request): JsonResponse
    {
        $this->scoped($request)->whereNull('seen_at')
            ->update(['seen_at' => now(), 'updated_at' => now()]);

        return response()->json(['unread_count' => $this->unreadQuery($request)->count()]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<NotificationRecipient> */
    private function scoped(Request $request)
    {
        return NotificationRecipient::query()->for(
            User::class,
            (int) $request->user()->id,
            ActiveSchool::getOrFail()->id,
        );
    }

    /** @return \Illuminate\Database\Eloquent\Builder<NotificationRecipient> */
    private function unreadQuery(Request $request)
    {
        return $this->scoped($request)->whereNull('read_at');
    }
}
