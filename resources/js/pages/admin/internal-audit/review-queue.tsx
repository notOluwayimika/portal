import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import {
    batchView,
    queueView,
    selectAllOnPage,
} from '@/lib/internal-audit-queue';
import type {
    BatchResponse,
    BatchView,
    PendingResponse,
} from '@/lib/internal-audit-queue';

/**
 * INTERNAL AUDIT'S REVIEW QUEUE.
 *
 * Every decision this screen makes lives in `@/lib/internal-audit-queue` and is asserted by
 * `internal-audit-queue.test.ts` under vitest — the only bin/quality step that executes application
 * JS. This file maps those decisions to copy and colour and does nothing else, which is the same
 * split `parent/finance.tsx` uses over `parent-finance-view.ts`.
 *
 * THREE THINGS ARE LOAD-BEARING HERE, and each is a way this screen could lie.
 *
 * 1. THE PENDING COUNT IS THE MOST PROMINENT THING ON THE PAGE, and it is `pagination.total` — the
 *    whole queue in this school — never the number of rows rendered. This control's one failure
 *    mode that emits no audit row at any severity is a bill NOBODY REVIEWS; a number that grows is
 *    the only instrument that will ever show it. It is not behind a filter and there is no filter
 *    on this screen to put it behind: a count that silently describes a subset is worse than no
 *    count, because it reads as the whole.
 *
 * 2. AN EMPTY QUEUE AND A BROKEN FEED DO NOT RENDER ALIKE. Nothing pending is reassuring and true;
 *    a failed request means the queue is UNKNOWN. Rendered the same, this screen says "all clear"
 *    at the moment the truth is "I could not ask" — and says it most convincingly when the network
 *    is worst. `queueView` makes them separate cases; this file gives them separate copy, separate
 *    colour, and a retry on only one of them.
 *
 * 3. THE BATCH RESULT RENDERS PER INVOICE. The endpoint answers 207 with an outcome each precisely
 *    so a partial batch cannot be read as done. Collapsing it to "done" would recreate, on the
 *    operator's side, the exact failure the endpoint refuses — and there would be no audit row to
 *    contradict it.
 */
export default function InternalAuditReviewQueue() {
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [response, setResponse] = useState<PendingResponse | null>(null);
    const [selected, setSelected] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<BatchView | null>(null);

    /**
     * A PROMISE CHAIN, NOT async/await, AND THE REASON IS THE `catch`.
     *
     * `react-hooks/set-state-in-effect` fired on the effect below, and it took two fixes because
     * there were two causes.
     *
     * The first was real and obvious: this opened with `setLoading(true); setFailed(false)`, which
     * run SYNCHRONOUSLY in the effect body. Gone — the initial loading state is declared in
     * `useState(true)` above, so the first render is already "loading" and the effect has nothing
     * to announce.
     *
     * The second is the one worth writing down, because it survived that fix and is not obvious.
     * With `try { await … } catch { setState() }`, THE CATCH CAN RUN IN THE SAME TICK AS THE
     * EFFECT: if the awaited expression throws BEFORE it yields a promise, control reaches the
     * catch synchronously, and the rule is right to say so. Measured — a probe differing from this
     * function only in having a state-setting `catch` reproduced the error, and one without it did
     * not.
     *
     * `.then().catch().finally()` cannot do that. A promise continuation is always scheduled, so
     * every setState here is unreachable synchronously by construction rather than by inspection.
     * That is why this is a chain and not the async/await it reads worse as.
     *
     * A retry from the button is an EVENT HANDLER, not an effect, so it sets `loading` itself
     * before calling. That is the one place the transition is real: on mount there is no earlier
     * state to move away from.
     */
    const load = useCallback(
        () =>
            axios
                .get<PendingResponse>('/api/internal-audit/invoices/pending')
                .then(({ data }) => {
                    setResponse(data);
                    setFailed(false);
                })
                .catch(() => {
                    // The response is CLEARED, not left stale: a failure that kept the previous
                    // page would show rows the server has not confirmed, under a banner saying the
                    // feed is down.
                    setResponse(null);
                    setFailed(true);
                })
                .finally(() => setLoading(false)),
        [],
    );

    useEffect(() => {
        void load();
    }, [load]);

    const view = queueView({ loading, failed, response });

    const approve = async () => {
        if (selected.length === 0) {
            return;
        }

        setBusy(true);

        try {
            // 207 is a SUCCESS status to axios's default validateStatus (< 300 is not the rule —
            // axios accepts 2xx), so the partial case arrives here rather than in catch.
            const { data } = await axios.post<BatchResponse>(
                '/api/internal-audit/invoices/approve',
                {
                    uuids: selected,
                },
            );
            setResult(batchView(data));
            setSelected([]);
            await load();
        } catch {
            setResult(null);
            setFailed(true);
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Head title="Invoice review queue" />
            <div className="mx-auto max-w-5xl space-y-6 p-6">
                <header>
                    <h1 className="text-xl font-bold">Invoice review queue</h1>
                    {/* THE COUNT, FIRST AND LARGEST. Rendered from pendingTotal for every state in
                        which it is KNOWN — and deliberately not rendered at all when it is not,
                        rather than shown as zero. */}
                    {view.kind === 'rows' && (
                        <p
                            className="mt-2 text-3xl font-extrabold"
                            data-testid="pending-total"
                        >
                            {view.pendingTotal} awaiting review
                        </p>
                    )}
                    {view.kind === 'empty' && (
                        <p
                            className="mt-2 text-3xl font-extrabold text-emerald-700"
                            data-testid="pending-total"
                        >
                            0 awaiting review
                        </p>
                    )}
                </header>

                {view.kind === 'loading' && <p>Loading the queue…</p>}

                {/* THE TWO STATES THAT MUST NOT LOOK ALIKE. */}
                {view.kind === 'empty' && (
                    <p
                        className="rounded-lg bg-emerald-50 p-4 text-emerald-900"
                        role="status"
                    >
                        Every bill in this school has been reviewed and
                        released.
                    </p>
                )}
                {view.kind === 'failed' && (
                    <div
                        className="rounded-lg bg-red-50 p-4 text-red-900"
                        role="alert"
                    >
                        <p className="font-bold">
                            The queue could not be loaded.
                        </p>
                        <p>
                            This is <strong>not</strong> an empty queue — the
                            number awaiting review is unknown.
                        </p>
                        <button
                            type="button"
                            className="mt-2 underline"
                            onClick={() => {
                                // Set HERE, not in load(): this is an event handler, where moving
                                // back to "loading" is a real transition the user asked for. On
                                // mount there is no earlier state to move away from, which is why
                                // load() itself writes nothing before its first await.
                                setLoading(true);
                                setFailed(false);
                                void load();
                            }}
                        >
                            Try again
                        </button>
                    </div>
                )}

                {view.kind === 'rows' && (
                    <>
                        <div className="flex items-center gap-3">
                            <button
                                type="button"
                                className="rounded border px-3 py-1"
                                onClick={() =>
                                    setSelected(selectAllOnPage(view))
                                }
                            >
                                {/* LABELLED AS THE PAGE, NEVER "all". The batch cap is 100 and a page
                                    is at most 100; a control meaning "all 900" builds a request the
                                    server refuses with a 422 the auditor cannot act on. */}
                                Select all {view.rows.length} on this page
                            </button>
                            <button
                                type="button"
                                className="rounded bg-slate-900 px-3 py-1 text-white disabled:opacity-50"
                                disabled={busy || selected.length === 0}
                                onClick={() => void approve()}
                            >
                                Release {selected.length} selected
                            </button>
                        </div>

                        <ul className="divide-y rounded-lg border">
                            {view.rows.map((row) => (
                                <li
                                    key={row.uuid}
                                    className="flex items-center gap-3 p-3"
                                >
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(row.uuid)}
                                        onChange={(e) =>
                                            setSelected((prev) =>
                                                e.target.checked
                                                    ? [...prev, row.uuid]
                                                    : prev.filter(
                                                          (u) => u !== row.uuid,
                                                      ),
                                            )
                                        }
                                    />
                                    <span className="font-mono">
                                        {row.number}
                                    </span>
                                    <span className="ml-auto text-sm text-slate-500">
                                        {row.issued_at}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {/* THE BATCH RESULT, PER INVOICE. `allApproved` is the only thing that may say
                    "released"; anything else names what did not go through and why. */}
                {result !== null && (
                    <section
                        className="rounded-lg border p-4"
                        data-testid="batch-result"
                    >
                        {result.allApproved ? (
                            <p role="status">
                                All {result.approvedCount} selected bills were
                                released.
                            </p>
                        ) : (
                            <div role="alert">
                                <p className="font-bold">
                                    {result.approvedCount} released,{' '}
                                    {result.refusedCount} not released.
                                </p>
                                <ul className="mt-2 list-disc pl-5">
                                    {result.refused.map((row) => (
                                        <li key={row.uuid}>
                                            <span className="font-mono">
                                                {row.uuid}
                                            </span>{' '}
                                            — {row.message}
                                        </li>
                                    ))}
                                </ul>
                                {result.approvedCount > 0 && (
                                    <p className="mt-2 text-sm text-slate-600">
                                        The {result.approvedCount} released
                                        above are done and do not need
                                        repeating.
                                    </p>
                                )}
                            </div>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}
