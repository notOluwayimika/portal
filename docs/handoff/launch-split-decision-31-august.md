# The launch splits in two: read-only on 6 September, payments on 13 September

> ## ⚠️ SUPERSEDED — BOTH DATES ARE OFF, AS OF 2026-09-03
>
> **5 September (deploy), 6 September (read-only) and 13 September (payments) are all superseded,
> pending a Brookstone directive. Decision: Segun.**
>
> **THE SPLIT ITSELF IS NOT WITHDRAWN** — the reasoning below for shipping the read half before the
> pay half is unchanged, and none of it was an argument about a calendar. What is withdrawn is the
> dates.
>
> **Current state, and the only place it is recorded:**
> [`post-deploy-tasks.md`](post-deploy-tasks.md) § Phase 0 — which also carries the deploy sequence,
> the backfill-ordering trap, and the constraint that forced this (nobody in production holds
> `internal_auditor`, so the bulk run would create bills no seat could release).
>
> **The sequence is deliberately NOT repeated here.** Two documents asserting different dates is how
> the next reader gets the wrong one, and two copies of a runbook is the same failure with more
> steps. One copy, one place.
>
> Directive 3's Friday gateway cutoff below is a date too, and is superseded with the rest.

**Status:** decided 31 August 2026. **Decision:** Segun, as project lead, on a recommendation he
asked me to make and then approved as written. **Directives 1-3 are his.**

---

## The decision

**6 September** — the parent portal ships READ-ONLY. A guardian signs in and sees what each ward
owes.

**13 September** — the pay half: gateway initiation, and everything it depends on.

## Why the split, in the order the reasons matter

**The pay half's date is set by its least-ready dependency, and it has five.** The gateway migration
is behind on production, the settlement bank account does not exist as a row, the Paystack fee
gross-up is unsettled, partial payment is unanswered, and IA payability is a specification Developer
2 received on 31 August. Shipping both halves together means the thing nobody can date gates the
thing that is finished.

**The failure modes are different classes on a date that cannot move.** A read-half defect shows a
parent a wrong number. A pay-half defect takes money — a misrouted reference, a silent settlement
failure, or a payment against a bill nobody reviewed. Resumption week has the least capacity to
absorb either, and the gateway is the one that generates support calls per transaction rather than
per screen.

**The read half is most of the promise.** A guardian seeing what each ward owes is the thing they
have never had. Pay-now a week later is a smaller disappointment than a gateway that misroutes on
day one.

## Directive 1 — the withhold predicate is a condition of shipping, not a follow-up

The read half ships ONLY with unreviewed invoices withheld from the parent feed. Unmodified, that
screen puts unreviewed bills in front of parents on day one, which is the state Brookstone ruled out
on 31 August (`brookstone-answers-31-august.md` §2). Landed the same day in `d4536ae1`.

**Merging it does not protect anyone. The migration running ON PRODUCTION does** — and production is
behind on migrations. The deploy needs a date of its own, before the portal is reachable, and
`open-findings.md` Finding 0 is the standing warning about migrations landing in cutover week.

**THE DATE IS NOW SET: 5 SEPTEMBER 2026**, decided by Segun on 2 September — one day before the read
half opens. Recorded in [`post-deploy-tasks.md`](post-deploy-tasks.md) § Phase 0, with what it costs
(the IA review slice must be MERGED by end of 4 September; the 5th is a deploy day, not a build day)
and the backfill-ordering trap it creates.

## Directive 2 — the balance default

If Brookstone have not answered the parent-balance question by Wednesday 3 September, proceed with
the displayed balance EXCLUDING bills pending review. Safe because it is a subset: the predicate is
needed either way, and if they later require the full balance that is additive — a withheld-count
field plus a third empty state — not a rework.

## Directive 3 — the gateway cutoff

Friday is the hard cutoff for Brookstone to provide the production bank account and gateway
readiness. If either is missing on Friday, 13 September stops being a date and is re-quoted rather
than allowed to slip quietly.

---

## Recorded here because it exists nowhere else: the `mayPay` approval

Developer 2's `GuardianPaymentAuthorisation::mayPay` ownership seam — approved **by Segun**, without
adding new system permissions. That approval was relayed through me and I later mis-stated it as
mine; it is his. `mayPay` itself was written on **25 August 2026** in `f6398739`, and until 31
August
the approval appeared in no repository document at all — a decision gating another developer's
build,
living only in chat.

**It stands unchanged.** What was added on 31 August is one axis it does not cover: release state.
`mayPay` answers ownership and its own docblock disclaims payability — void, settled, currency — and
release is now a fourth alongside those. The initiation endpoint must check it independently of the
feed, because withholding an invoice from `GET /api/parent/finance/wards` stops the button rendering
but does not refuse a POST naming a remembered or guessed uuid.
