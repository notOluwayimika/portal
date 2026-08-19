# `already_billed` is decided before any failure path runs, and wins over failures that would follow

**Raised** 2026-08-19, on `feat/u6-bulk-invoice-run` (U6 commit 3), by cold review.
**Severity** ticket. This is **stated behaviour**, not a defect — recorded so a future reader does
not "fix" it into something worse.

## The behaviour

`ProcessBulkInvoiceRun::bill()` asks one question first:

```php
$existing = $invoices->activeScheduledInvoiceIdForEnrollment($enrollment->enrollmentId, $this->schoolId);

if ($existing !== null) {
    // record already_billed and return
}
```

Only if that returns null does it call `GenerateInvoice`. So a student who is **both** already billed
**and** would have failed — the episode has since vanished, so `findByUuid()` would return null and
the Action would throw *"No billable enrollment found for the given reference."* — is classified
`already_billed`, and the failure is never observed because the code that would observe it never
runs.

## Why that is the right order

The question `bill()` is answering is *"does this run need to raise an invoice for this student?"*,
and the answer is **no** in that case. It is no for a reason that is true and useful: the term bill
exists, it is issued, it is not void, and re-running will never produce a second one.

Reversing the order — attempt first, classify second — would make the run raise (and roll back, and
log) work for every student it already billed, which is the entire cohort on the second run of a
re-run. The recovery path is the common path here, and it must be the cheap one.

## What it costs, honestly

A run's `failed_count` is **not** a complete census of the students whose episodes are broken. It
counts the broken episodes the run had a reason to touch. An operator diagnosing "which students in
this cohort have a problem" gets the ones that were not already billed, and gets silence about the
rest.

That matters for exactly one future consumer: a screen that offers "retry the failures". Such a
screen must not present `failed_count` as the size of the problem.

## If it is ever worth changing

The change is not to reorder. It is to make the already-billed branch **also** verify the episode
still resolves, and record a distinct outcome (`already_billed_but_episode_missing`) when it does
not — a fifth enum case, a trigger-domain change, and a screen that can explain the distinction.
That is a real feature with a real cost, and nothing has asked for it.
