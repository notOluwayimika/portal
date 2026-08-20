# A supplementary invoice has no duplicate backstop, and these are the first client-reachable
# routes without one

**Raised by** `feat/u7-supplementary-invoice-wire`, from that branch's cold review.
**Not a defect in the branch** — the absence is by design and there is no correct uniqueness key
to add. What is new is that a client can now reach it.

## The mechanism, re-derived from the code

`App\Finance\Enums\InvoiceKind::isEpisodeExclusive()` returns true for `Scheduled` and false for
`Supplementary`. Both of the Action's duplicate defences are gated on it:

```php
app/Finance/Actions/GenerateInvoice.php:268
    if ($kind->isEpisodeExclusive()) {
        $this->assertNoActiveInvoice($enrollment->schoolId, $enrollment->enrollmentId);
    }

app/Finance/Actions/GenerateInvoice.php:339
    if ($kind->isEpisodeExclusive() && $this->isActiveEnrollmentCollision($e)) {
        throw new BusinessRuleException('This enrollment already has an active TERM invoice. …');
    }
```

Underneath both sits the real authority — `UNIQUE(school_id, active_enrollment_key)` over the
stored generated column `IF(status = 'issued' AND kind = 'scheduled', student_curriculum_id, NULL)`
(`database/migrations/2026_08_18_100000_…:225`). A supplementary invoice computes `NULL`, NULLs do
not collide in a MySQL unique index, and so **nothing at any layer refuses a second identical
supplementary invoice.**

That is proved positively, not inferred. `tests/Feature/Finance/SupplementaryInvoiceWireTest.php`,
arm **`c — the generated-column unique index refuses a second SCHEDULED and permits a second
SUPPLEMENTARY`** (`:198`), bypasses the Action entirely and inserts raw:

```php
:210  expect(swDriverCode(fn () => swRawInsert($scheduled, ['kind' => 'scheduled'])))->toBe(1062);
:217  expect(swDriverCode(fn () => swRawInsert($scheduled, ['kind' => 'supplementary'])))->toBeNull()
:218      ->and(swDriverCode(fn () => swRawInsert($scheduled, ['kind' => 'supplementary'])))->toBeNull()
```

Two consecutive supplementary inserts on one episode, both returning driver code `null`. The arm was
written to prove the feature works; read the other way it is this ticket's evidence.

## What is new, and it is not the absence

**Every invoice-creation path a client could reach before this branch was backstopped.** Both
generate routes named `InvoiceKind::Scheduled` as a literal, so a repeat — a retried POST, a
double-click, a duplicate job — met the unique index and was refused, with the Action translating
1062 into a friendly 422. That refusal was free and nobody had to think about it.

After this branch, `POST /v1/finance/students/{student:uuid}/invoices` and
`POST /v1/finance/invoices` can both request `kind=supplementary`, and on that path the backstop is
gone. So:

- a **retried POST after a client timeout** — the request that timed out may well have committed;
- a **double-submitting API client**, or any caller of the harness route
  `POST /v1/finance/invoices`, which no UI mediates;
- a **retry** at any layer that assumes POSTs are idempotent because they used to be refused

each creates a second identical supplementary invoice. Each one posts its own
`LedgerEntryType::Charge` through `SubledgerPoster` (`GenerateInvoice.php:300-313`) and raises the
student's balance again. The money is real and the duplicate is indistinguishable from a legitimate
second charge — a student genuinely can be billed twice for two separate damages on the same day.

**The only brake anywhere is client-side and single-tab:**
`resources/js/components/finance/new-invoice-modal.tsx:737` — `disabled={submitting || blocked !== null}`.
It survives neither a page reload nor a second tab nor a non-browser caller.

## Why there is no "just add a unique key"

Unbounded supplementary invoices are the *intended semantics*. An episode legitimately carries a
damaged locker, a trip, a lost book and a late fee, and two of those can be the same amount on the
same day. `InvoiceKind`'s own docblock states it: supplementary charges "are unbounded in number by
their nature". Any uniqueness constraint over (school, episode, kind) — or over
(school, episode, kind, amount, day) — refuses a legitimate write. There is no natural key, which is
why the design has none, and why this is a ticket rather than a bug.

## Two options, priced. No recommendation is made here.

### Option A — accept the exposure

**Cost:** duplicates happen at whatever rate clients retry, and are found by a human reading a
statement. **Recovery exists and is not cheap:** reversing one is a maker-checker void request per
invoice (`SubmitVoidRequest` → `ApproveVoidRequest`, a second signature, ADR 0040), which is the same
path a genuine mistaken charge takes. **Detection is the weak part** — nothing looks for duplicates,
and the statement does not even say which invoices are supplementary
(`docs/handoff/tickets/nothing-shows-which-invoices-are-supplementary.md`), so two identical rows
read as two identical charges rather than as one charge twice.

**What makes this defensible:** the blast radius is one student's balance, the duplicate is visible
to anyone reading the statement, and no automated process consumes invoice counts in a way a
duplicate would corrupt silently.

**What makes it uncomfortable:** it is money posted to a student's balance with no automatic
detection, on a route that has no UI in front of it.

### Option B — an idempotency key on the two generate routes

What it would need, stated concretely so the cost is visible rather than assumed:

- **Where the key comes from.** The client, as an `Idempotency-Key` request header — a uuid the
  modal mints once per dialog-open (not per submit, or a retry after a validation error gets a new
  key and defeats the point). The harness route's callers would have to send one too, or be exempt
  and keep today's behaviour, which is a decision in itself.
- **What stores it.** A new table — `finance_request_idempotency` or similar — keyed
  `UNIQUE(school_id, key)`, holding the resulting invoice id and the response status. It must be
  written **inside** `GenerateInvoice`'s existing transaction, or the window between commit and
  key-write is exactly the failure being closed. Note this is a new table in a bounded context whose
  other tables are append-only with immutability triggers; whether it inherits that discipline is
  part of the decision.
- **What window.** A retry-after-timeout arrives in seconds; a double-click in milliseconds; an
  operator legitimately raising a second identical charge might do so minutes later. 24 hours is the
  conventional answer and is also long enough to refuse a legitimate second charge, so the window is
  a real trade rather than a parameter. Rows need pruning, which is a scheduled job nobody has yet.
- **What it does on a hit.** Return the **original** invoice with 200 (not 201), rather than
  refusing — a client that retried because it never saw the first response needs the first
  response, and an error would send it into a retry loop. That means storing enough to rebuild the
  body, or re-serialising the stored invoice id through `InvoiceResource`.
- **What it does NOT solve:** two genuinely separate submissions from two tabs, each with its own
  key, are two legitimate requests by construction and stay two invoices.

**Cost summary:** one migration, one table, a change inside the Action's transaction, a header
contract on two routes, a client change, a pruning job, and a decision about the harness route.
Against: the only class of duplicate it stops is the machine-generated one, which is also the one a
human is least likely to notice.

## Scope note

This ticket is about **invoice generation only**. Whether the same exposure exists on the payment
routes, the credit-note routes or the opening-balance import was **not** checked and should not be
inferred from this document either way.
