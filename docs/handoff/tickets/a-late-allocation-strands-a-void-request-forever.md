# A late allocation strands a void request forever, and only the Executive Director can free it

**Status:** open · **Opened:** 2026-09-05 · **Found by:** the void-approval investigation of
2026-09-05 · **Severity:** fix — it needs a ruling before it needs code

## What is true

A void request is submitted while the invoice is eligible. A payment is then allocated to that
invoice **before the checker gets to it**. From that moment:

1. **The request can never be approved.** `app/Finance/Actions/ApproveVoidRequest.php:57` locks the
   invoice row, and `app/Finance/Actions/ApproveVoidRequest.php:65-67` re-runs `VoidEligibility::blocker()` and raises:

   ```php
   $invoice = Invoice::query()->whereKey($request->invoice_id)->lockForUpdate()->firstOrFail();
   …
   $blocker = VoidEligibility::blocker($invoice);
   if ($blocker !== null) {
       throw new BusinessRuleException($blocker);
   }
   ```

2. **It is not auto-closed.** Nothing moves it out of `submitted`. It stays in the checker's queue,
   presenting as work, permanently.

3. **It holds the invoice's single open slot.** A second submit is refused twice over — in PHP at
   `app/Finance/Actions/SubmitVoidRequest.php:63-69` (*"A void request for this invoice is already
   awaiting approval."*), and in the database by the generated column and its unique index,
   `database/migrations/2026_07_25_140000_create_finance_void_requests.php:87`

   ```sql
   GENERATED ALWAYS AS (IF(status = 'submitted', invoice_id, NULL)) STORED
   ```

   with `database/migrations/2026_07_25_140000_create_finance_void_requests.php:91` `ADD UNIQUE finance_void_requests_open_unique (school_id, open_key)`. A decided
   request has `open_key = NULL` and does not collide; a **submitted** one does.

## The consequence, plainly

**The invoice cannot be voided by anyone**, and it cannot be re-requested. The only act that frees
it is a **rejection** — and rejection carries `finance.invoice.void-request.reject`, which exactly
one role holds.

Re-derived by executing `RbacSeeder::grantsMap()` with its fragments expanded, rather than by
grepping per permission:

```
HOLDERS of finance.invoice.void-request.reject:   executive_director   (1 of 15 roles)
HOLDERS of finance.invoice.void-request.approve:  executive_director
HOLDERS of finance.invoice.void-request.submit:   accounts_officer
```

`super_admin` is not a way round it: the terminal segment is a checker one, so
`ApprovalAbility::isExcludedFromSuperAdminBypass('finance.invoice.void-request.reject')` returns
**true** (`app/Support/ApprovalAbility.php:40`, `app/Support/ApprovalAbility.php:88`) — ADR 0040.

So: **the Executive Director must reject a request that was correct when it was made**, and the
rejection record will say that a correct request was refused. That is the audit trail this state
produces, and it is a misleading one.

**Rejecting frees the SLOT; it does not restore voidability.** The allocation is monotonic, so the
invoice remains unvoidable afterwards — what the rejection buys is the ability to submit again, and
the next submit is refused at once by `SubmitVoidRequest`'s own eligibility check. The bill is
simply not voidable any more, by anybody, and the stranded request was only ever hiding that.

Note the asymmetry that makes it worse than a queue nuisance. `app/Finance/Actions/SubmitVoidRequest.php:21-27` argues
that refusing at submit is right because a persisted-but-doomed request is *"noise in the checker's
queue that also burns the invoice's single open-request slot (open_key) for nothing"*. **That is
exactly the state this ticket describes** — the design refuses to create it at submit and then has
no answer when time creates it anyway.

## Why it matters

**This is not hypothetical and it is not rare on a busy book.** The window is submit → approve,
which is human latency: a checker who reads their queue once a day leaves a day-wide window on
every request. Anything that allocates in that window opens it — a parent paying online, a bursar
recording a transfer, an allocation proposal being accepted.

It is also **silent**. Nothing errors until a human clicks approve, and what they then see is a
refusal sentence about a payment, on a request they did not make, with no indication that the
request is now permanently dead rather than temporarily blocked.

**IT IS NOT INVISIBLE IN TESTS, AND AN EARLIER DRAFT OF THIS TICKET SAID IT WAS.** The state is
exercised by an acceptance arm — `tests/Feature/Finance/FinanceApiAcceptanceTest.php:933`, VOID
PROOF 5 — which submits while eligible (`tests/Feature/Finance/FinanceApiAcceptanceTest.php:940`), allocates a payment (`tests/Feature/Finance/FinanceApiAcceptanceTest.php:943-944`), approves, and
asserts the 422 (`tests/Feature/Finance/FinanceApiAcceptanceTest.php:947`) with no reversal, the row still `Submitted` (`tests/Feature/Finance/FinanceApiAcceptanceTest.php:950`) and the invoice still
`issued` (`tests/Feature/Finance/FinanceApiAcceptanceTest.php:951`). **That is why the refusal is known to work.**

What no arm asserts is the two things that make it a STRAND rather than a refusal:

- **PERMANENCE.** Re-derived by counting approve calls per void-proof arm:
  PROOF 5 makes **one**. No arm re-attempts approval after a refused one, so nothing asserts the
  request can never be approved at any later time. (PROOF 4 does make two approve calls, but that
  is a second approval after a SUCCESSFUL void — the terminal/one-way axis, not this one.)
- **THAT THE SLOT STAYS HELD.** PROOF 5's body contains **zero** second-submit attempts. The
  held-slot half is asserted only for the CREDIT-NOTE case, at
  `tests/Feature/Finance/FinanceApiAcceptanceTest.php:975-977` (PROOF 6's *"a FRESH submit is also
  refused up front"*), and PROOF 6's body contains exactly one such attempt. Nothing does the same
  for the allocation case.

So the gap is real and it is **smaller than an absence of coverage**: the refusal is proven, the
consequences of it are not.

**Environment:** every environment with real payment traffic. The arm above runs in the acceptance
suite, so the refusal is regression-protected; the strand is not.

## The options — RECORDED, NOT CHOSEN

Both need a ruling that has not been given. Neither is obviously right and they are not exclusive.

**(a) Auto-reject on the failed re-check, with a stated reason.** When `ApproveVoidRequest`'s
re-check fires, transition the request to `rejected` with a system-authored reason naming the
allocation. Frees the slot immediately and truthfully. The objections: it writes a decision **no
human made**, into a table whose whole purpose is recording who decided what; `decided_by` is
nullable (`database/migrations/2026_07_25_140000_create_finance_void_requests.php:72`) so it is expressible, but a rejection with no decider is a new
shape in a maker-checker table and the maker≠checker triggers were built assuming a person on both
sides. It also happens inside the checker's request, so the checker's click produces a rejection
they did not choose.

**(b) Surface it as un-approvable on the decisions queue.** Leave the row alone and let the queue
show, per request, that the re-check would refuse and why. Nothing is written that a human did not
do, and the Executive Director rejects with full knowledge instead of blind. The objections: the
slot stays held until they act, so the invoice stays unvoidable in the meantime; and it needs the
queue to run `VoidEligibility::blocker()` per row, which is a read the feed does not do today.

**What must be decided, whichever is taken:** whether a rejection with no human decider is
acceptable in a maker-checker table, and whether the request should be freed automatically or only
by a person who has been told why. Those are the same question asked at two different levels, and
the answer to the first determines whether (a) is available at all.

## What would close it

A ruling on (a) vs (b), then the mechanism it implies.

**THE ARM ALREADY EXISTS AND MUST BE EXTENDED, NOT WRITTEN.**
`tests/Feature/Finance/FinanceApiAcceptanceTest.php:933` (VOID PROOF 5) already submits, allocates
and asserts the refusal. What it must GAIN is the two assertions measured above:

1. **A second approval attempt**, asserting the refusal is not a one-off — that the request is
   permanently un-approvable rather than transiently blocked.
2. **A second submit attempt**, asserting the `open_key` slot is still held for the ALLOCATION case
   — the assertion PROOF 6 already makes at `tests/Feature/Finance/FinanceApiAcceptanceTest.php:975-977` for the credit-note case, absent here.

Whichever of (a) or (b) is ruled, those two assertions change: under (a) the row would be `rejected`
and a fresh submit would SUCCEED; under (b) it would still be `submitted` and a fresh submit would
still be refused. **So the arm is also the thing that distinguishes the two remedies**, which is a
better reason to extend it than to document the current behaviour.
