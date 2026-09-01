# The release rule has TWO implementations, and they will diverge on a known trigger

> **RESOLVED — 2026-09-01, `refactor/one-definition-released-to-payers`.**
>
> There were THREE, not two: the ticket below counts `isReviewed()` and `scopeReviewed()`, and a
> third inline `whereNull('reviewed_at')` sat in `InvoiceReadModel::guardianAccountPositionForStudent()`
> — the withheld half of the parent balance projection.
>
> All three now route through `Invoice::RELEASE_STAMP_COLUMN`, read by exactly two members:
> `isReleasedToPayers()` (PHP) and `scopeReleasedToPayers(bool $released = true)` (SQL, both
> directions, with `withheldFromPayers()` a call INTO it rather than its own predicate). Renamed
> for the question the caller asks rather than for the column, so a rejection shape that STAMPS a
> refused bill does not silently make the name true.
>
> **NO DEPRECATED ALIAS.** `isReviewed()` and `scopeReviewed()` are gone, not wrapped. An alias kept
> "for safety" is the thing that never gets removed, and the whole point of the commit is that one
> definition exists.
>
> The redundancy the three copies provided — an accidental cross-check, where updating one could
> expose the others — is replaced by prevention rather than deleted:
> `tests/Arch/ReleasedToPayersHasOneDefinitionTest.php` asserts the column name appears in app/
> CODE exactly once, at the constant. A fourth spelling cannot be written without a red.

**Raised:** 2026-09-01 · **From:** `feat/gateway-initiate` · **For:** Developer 1 · **Severity:** fix, before the rejection answer lands

## The ask, concretely

Not "add a predicate". **Collapse these two onto one definition and expose it per-invoice.**

```php
// app/Finance/Models/Invoice.php
public function isReviewed(): bool          { return $this->reviewed_at !== null; }   // PHP
public function scopeReviewed(Builder $q)   { return $q->whereNotNull('reviewed_at'); } // SQL
```

Two implementations of one rule — one in PHP, one in SQL. They agree today **only because both
happen to mean "not null"**. Nothing ties them together, and no test asserts they answer alike.

## This is not "they might drift someday". The divergence has a trigger and a direction.

**The trigger:** Brookstone's answer on rejection modelling. If a rejected bill ends up stamping
`reviewed_at`, the rule becomes "stamped AND approved" rather than "stamped".

**The direction:** whoever implements that will almost certainly fix `scopeReviewed()` — because the
feed's withhold is where the visible bug appears. A rejected bill showing up in a parent's list is
the symptom someone reports.

`isReviewed()` keeps the old meaning silently. **And it is the one the payment endpoint calls.** So
the reader that gets missed is the one guarding money, and the failure mode is a parent successfully
paying a bill an auditor has just refused.

## What is in the tree meanwhile

`InitiateGatewayPayment` calls `isReviewed()` and its docblock says exactly what that is: a column
test behind a wrapper, that does not survive a rejection which stamps the column, to be swapped for
the shared predicate. It does **not** describe itself as the release check.

Kept rather than removed because no release check at all fails OPEN on the axis that matters, which
is strictly worse than a check correct under the current rejection shape.

**No private predicate was written.** A third reader of a rule that already has two implementations
is the wrong direction, and this is Developer 1's to own.

## Also worth knowing

Nothing in the codebase WRITES `reviewed_at` yet — the Internal Audit review action is not built. So
the gateway's own fixture cannot create an unreleased invoice "through whatever releases bills",
because nothing does. It leaves the column unset (the natural state) and stamps it directly for the
released case, with that gap named in the test. The predicate's own known-negative belongs in
Finance once the action exists.
