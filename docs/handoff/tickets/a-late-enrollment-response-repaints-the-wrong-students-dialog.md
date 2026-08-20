# A late enrollment response repaints the wrong student's New invoice dialog

**Pre-existing.** The race has been in `new-invoice-modal.tsx` since the dialog resolved its
episode server-side. **What `feat/u7-supplementary-invoice-wire` changed is the blast radius**,
not the bug.

## The mechanism

`NewInvoiceModal`'s `loadEnrollment` is a `useCallback` over `[student.uuid]`. Opening the dialog
for a student fires it; the effect re-fires when `student.uuid` changes. It performs a plain
`axios.get` of `billableEnrollment.url(student.uuid)` and, on resolution, calls
`setEnrollment(data)`.

There is **no `AbortController`, no axios `CancelToken`, and no generation token** — nothing that
ties a response to the request that is still wanted. Two requests in flight resolve in whatever
order the network gives them, and the last one to resolve wins the state.

So: open the dialog for student A on a slow response, close it, open it for student B. B's request
may resolve first; A's arrives afterwards and calls `setEnrollment(dataA)` while B's dialog is on
screen. `student.uuid` is B's — the prop is correct throughout — but `enrollment` now holds A's
episode.

**This is a different property from the one that branch's report and
`docs/handoff/tickets/no-javascript-test-runner.md` discuss.** The *reset* on open is sound:
`loadEnrollment` calls `setEnrollment(null)` and `setInvoiceKind('scheduled')` synchronously before
its first `await`, so nothing stale is ever *selected*. This ticket is about what arrives
**after** that reset, from a request nobody cancelled.

## What this branch changed about it

Before U7, `already_invoiced` drove exactly one thing: the amber banner. A stale `true` showed a
banner about an episode the bursar was not looking at — wrong, visible, and self-correcting once
they read the episode line beside it.

After U7 the same field drives **three** things: the banner, the banner's new "choose Supplementary
charge instead" sentence, and — through `termBillLabel` — the *label on the invoice-kind select
itself*. A stale `true` now labels the Term bill option `"Term bill (will be rejected — void
first)"` for a student whose episode has no term invoice at all. That is an instruction to do
something unnecessary, printed on the control the bursar is about to use.

The episode line (`academic_context`) also comes from the same stale payload, so the dialog is
internally consistent and shows no seam.

## It misleads; it does not misbill. This distinction is the whole severity argument.

The POST is addressed by `student.uuid` — `generateForStudent.url(student.uuid)` — which is a
**prop**, not the fetched state. A stale `enrollment` cannot redirect the write. The server then
resolves the billable episode itself, server-side, through the ACL port, and applies the
`hasActiveScheduledInvoiceForEnrollment` guard against the *real* episode.

So the worst outcome is a bursar acting on a wrong screen:

- told to void a term invoice that does not exist (there is nothing to void; they go looking);
- or **not** warned when the real episode *is* already invoiced, submits a term bill, and gets the
  ordinary 422 refusal — the server's guard, doing its job.

**No wrong invoice is created.** The failure is a confusing screen and a wasted trip, not money.
That is the reason this is filed rather than fixed under the U7 branch, and the reason it is worth
filing rather than shrugging at: the misleading instruction now sits on the control itself.

## Nothing on this platform can red it

There is no JavaScript test runner (`docs/handoff/tickets/no-javascript-test-runner.md`), and this
property needs one more than most: it is not a pure function of props, it is *ordering between two
in-flight promises*, which even a runner would need to fake timers or a controllable transport to
exercise. The Pest suite never reaches `resources/js`. `tsc` types both orderings identically.
`bin/quality` has nothing to say about it.

Nor can a drive catch it reliably — reproducing it means making one response slow and another fast
on purpose, which the drive fixture has no mechanism for. **A drive that does not reproduce it is
not evidence it is absent.**

## Not fixed here, and the shape a fix would take

Deliberately left alone: the U7 branch was scoped to the wire and the control, and a concurrency
fix in a shared modal is not a thing to slip into an unrelated commit.

The conventional fix is small — an `AbortController` created per invocation of `loadEnrollment`,
aborted in the effect's cleanup, or a monotonically increasing request id captured in the closure
and compared before `setEnrollment`. Which one, and whether the same treatment is owed to
`loadPolicies` beside it (same file, same shape, same absence of cancellation) is the open part.
`loadPolicies` has no `[student.uuid]` dependency, so it does not carry the cross-student variant of
this race — it is listed because a fix that covers one and not the other invites the question later.

Whether other Finance modals share the pattern was **not** checked and should not be inferred from
this ticket either way.
