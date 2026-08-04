import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

export type NotificationRow = {
    id: string;
    sort_id: number;
    type: string | null;
    title: string;
    reason: string;
    read_at: string | null;
    seen_at: string | null;
    created_at: string | null;
    subject_type: string | null;
    subject_uuid: string | null;
    student_uuid: string | null;
};

/**
 * NOTIFICATION TYPE → deep link.
 *
 * ⚠️ RE-KEYED FROM subject_type, AND THE TWO GENUINELY DIVERGE. ResultReady navigates
 * by SUBJECT — it needs the student and enrolment uuids to reach one specific result
 * page. ApprovalRequested navigates by TYPE — all four approval families land on the
 * same checker queue, and the subject plays no part in the URL.
 *
 * A subject-keyed map cannot express the second without four identical entries, one per
 * Finance subject class, each ignoring its own key. Keying on type lets each builder
 * decide what it consumes: some read subject uuids, some read nothing.
 *
 * EXTENSIBLE, POPULATED ONLY FOR WHAT IS BUILT — an entry here is a promise that the
 * target route exists, and NotificationDeepLinkRouteTest checks that promise against
 * Laravel's route table rather than trusting it. That check is why the map reaching two
 * entries is the right moment to add it.
 *
 * RETURNS NULL RATHER THAN A BEST GUESS. Payload ids are not foreign keys, so a
 * withdrawn subject leaves a row that must render as readable history and navigate
 * NOWHERE. A link to a 404 is worse than no link — the parent taps it and is told their
 * child does not exist.
 */
const DEEP_LINKS: Record<string, (row: NotificationRow) => string | null> = {
    // Per-SUBJECT: the result page is keyed on (student, enrolment), so BOTH uuids are
    // required. Either missing means the subject is gone — no link.
    'result.ready': (row) =>
        row.student_uuid && row.subject_uuid
            ? `/students/${row.student_uuid}/results/${row.subject_uuid}`
            : null,

    // Per-TYPE: the checker's QUEUE. No subject uuid in the URL at all — a void request,
    // a credit note, a discount-policy change and a fee-schedule change all land here,
    // which is precisely why this map cannot be keyed on subject.
    'approval.requested': () => '/finance/approvals',
};

export function notificationDeepLink(row: NotificationRow): string | null {
    if (row.type === null) {
        return null;
    }

    return DEEP_LINKS[row.type]?.(row) ?? null;
}

/**
 * The unread count, polled.
 *
 * POLLING IS v1's ENTIRE REAL-TIME STORY, and that is a decision rather than a
 * gap: `BROADCAST_CONNECTION` is `log`, `config/broadcasting.php` is not
 * published, and there is no Reverb or Echo — a WebSocket would mean a new
 * long-running supervised process on a host that has none. For a school portal a
 * 45-second count is honest; nothing here is second-critical.
 *
 * THE INTERVAL IS NOT HARD-CODED HERE. It comes from the server
 * (`config/notifications.feed.poll_seconds`) so it can be widened without a
 * frontend deploy — which is the lever you want when the queue is backed up.
 *
 * POLLING PAUSES ON A HIDDEN TAB. A tab left open overnight would otherwise make
 * ~1,900 requests before anyone looks at it, and the count it fetches is stale
 * the moment the tab is hidden anyway.
 */
export function useUnreadCount(pollSeconds: number) {
    const [count, setCount] = useState(0);
    // Ref, not state: the fetch must not re-subscribe the interval on each tick.
    const inFlight = useRef(false);

    const refresh = useCallback(async () => {
        if (inFlight.current || document.hidden) {
            return;
        }

        inFlight.current = true;

        try {
            const { data } = await axios.get('/api/notifications/unread-count');
            setCount(data.unread_count ?? 0);
        } catch {
            // Deliberately silent. A failed poll is not worth a toast every 45
            // seconds, and the count simply stays at its last known value.
        } finally {
            inFlight.current = false;
        }
    }, []);

    useEffect(() => {
        // The initial fetch. The interval below IS the subscription this rule asks
        // for; this line only primes it, so the badge is not empty for the first
        // poll interval after a page load.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void refresh();

        const interval = window.setInterval(
            () => void refresh(),
            Math.max(15, pollSeconds) * 1000,
        );

        // Catch up immediately when the tab comes back, rather than making the
        // user wait out the remainder of an interval that ran while hidden.
        const onVisible = () => {
            if (!document.hidden) {
                void refresh();
            }
        };

        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.clearInterval(interval);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [refresh, pollSeconds]);

    return { count, setCount, refresh };
}
