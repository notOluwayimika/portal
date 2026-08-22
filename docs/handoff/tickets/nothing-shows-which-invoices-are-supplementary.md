# Nothing on any read path shows which invoices are supplementary

> **Status — being closed by `feat/u7-invoice-list-and-detail`.** Each path below carries its own
> CLOSED / OPEN marker, added as that path was closed rather than at the end. The ticket's own
> instruction — that whoever builds U7's list treats this as part of that work — is what that branch
> is doing.

**Raised by** `feat/u7-supplementary-invoice-wire` (the branch that made the state
reachable). **Not a defect in that branch** — it is what that branch's own change to the
write path now makes visible on the read paths, which it did not touch.

## What changed underneath the reads

Before this branch an enrollment episode could carry **at most one active invoice**, full
stop: the generated-column unique index allowed one, and both generate call sites named
`InvoiceKind::Scheduled` as a literal, so no client could ask for anything else. An
invoice number on a screen was therefore unambiguous — there was only ever one live
invoice per episode, and it was the term bill.

After this branch an episode can carry **one active term bill and any number of live
supplementary charges at the same time**. That is the intended state; #259's index was
re-keyed precisely to permit it. Every read path below still renders those invoices with
the same fields it used when only one could exist.

## The read paths, each checked rather than assumed

**1 — `app/Finance/Http/Resources/InvoiceResource.php`. The wire does not carry `kind` at
all, and this is the root of everything below it.** — **CLOSED** (U7 commit 1). The resource now
serialises `'kind' => $this->kind->value` beside `status`, and both values are asserted over HTTP on
both read directions — the generate 201 and the per-student read — by
`tests/Feature/Finance/InvoiceKindOnReadPathsTest.php` arms a and b. The original text follows. `toArray()` serialises fifteen keys —
`id`, `number`, `display_number`, `status`, `billed_to_name`, `academic_context`, `total`,
`outstanding`, `settlement_state`, the four `can_*`/`void_blocked_reason` flags, `lines`,
`cancelled_at`, `cancel_reason` — and `kind` is not among them. It is the **only** invoice
serialiser in the codebase: both generate routes answer their 201 through it, and
`GET /v1/finance/students/{student:uuid}/invoices` returns a collection of it. So no
client can distinguish the two kinds today even if it wanted to; nothing further down is
choosing not to show it.

The model does carry it — `Invoice::$casts` has `'kind' => InvoiceKind::class`
(`app/Finance/Models/Invoice.php:67`) — and `InvoiceReadModel::forStudent()` returns whole
models. The value is loaded and then dropped at the resource.

**2 — `resources/js/types/finance.ts`, the `Invoice` type.** — **CLOSED** (U7 commit 1). `InvoiceKind`
is declared beside `InvoiceStatus` and `Invoice.kind` mirrors the resource key. The words a screen
renders live in one place, `resources/js/lib/finance/invoice-kind.ts`, which the "New invoice"
modal's own select now reads from too, so the label a bursar picks at creation is the label they read
back everywhere afterwards. The original text follows. Mirrors the resource key for
key and has no `kind`. A screen adding a badge would have to widen this first.

**3 — `resources/js/pages/admin/finance/statement.tsx`, the invoices table.** Renders
`invoice.display_number` (`:399`, and again at `:516` where the row is matched against a
pending void request), `invoice.settlement_state` (`:426`, `:450`) and the money columns.
Two invoices on the same episode — the term bill and a "Damaged locker door" charge —
appear as two rows differing only in number and amount. The bursar cannot tell which is
which without opening the lines.

**4 — the statement's billed total.** `InvoiceReadModel::billedTotalForStudent()`
(`:60-68`) reduces `forStudent()` with `Money::plus` and applies **no `kind` filter**, so
the figure on the statement is now term bill *plus* supplementary charges with no
breakdown anywhere on the page. Arguably correct as a total — it is what the student owes
— and it is the number that changed meaning without changing shape.

**5 — the three modals that act on a chosen invoice.** — **CLOSED** (U7 commit 1). All three now
title themselves through `invoiceLabel()` — kind and number — and
`InvoiceKindOnReadPathsTest` arm c reds on either half being dropped (proved by mutation, not by
inspection). What that arm cannot see is written on it: there is no JavaScript test runner here, so
it is a text check, and the browser drive covers whether the title actually renders. The original
text follows. `request-void-modal.tsx:99`,
`issue-credit-note-modal.tsx:117` and `record-payment-modal.tsx:158` each title themselves
with `invoice.display_number` and nothing else. **This is the most expensive one.**
Voiding the wrong invoice discards its payment allocations, and the confirmation a bursar
reads before doing it now names a number that no longer implies which document it is.

**6 — `resources/js/pages/admin/finance/receipt.tsx` and
`app/Finance/Http/Controllers/PaymentReceiptController.php`.** The allocation rows are
built at `:156-157` from `$a->invoice?->displayNumber()` and
`$a->invoice?->academic_context`. A payment settled across an episode's term bill and its
supplementary charge produces two allocation lines carrying two **different invoice
numbers** and the **same `academic_context`**. The two lines are therefore distinguishable
— the numbers differ, and a reader can tell one row from the other. What is missing is the
**kind**: nothing on the receipt says which of them is the term bill and which is the
one-off charge, so the reader can separate the rows without being able to identify either.
That is the whole claim here, and it is the same claim as §3 — not a stronger one.
`PaymentResource`'s own `allocations` block (`:68-72`) is thinner still — `invoice_id` and
`amount`.

**Checked and NOT affected:** the bulk-invoice-run screens
(`resources/js/pages/admin/finance/bulk-invoice-runs/`) render no invoice kind and need
none — `ProcessBulkInvoiceRun:346` raises `InvoiceKind::Scheduled` as a literal, so every
invoice a run produces is a term bill by construction.

## Why this is a ticket and not part of that branch

U7's invoice list and detail are not built. There is no list row, no detail header and no
badge component for an invoice kind to live in, so the honest options were to add a field
nothing renders, or to record where it will have to be rendered when the screen exists.
This is the second.

## What closing it involves

`kind` on `InvoiceResource` and on the `Invoice` TS type is one line each and is the
prerequisite for all of it. After that the decision is per-screen, and the void/credit-note
/payment modal titles are the ones worth doing first, because they precede an irreversible
act. Whoever builds U7's list should treat this as part of that work rather than as a
follow-up to it.
