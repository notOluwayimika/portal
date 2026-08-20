# TICKET — `CurrentTerm` resolves arbitrarily when a session has two `active` terms

**Status:** open, not implemented. **Pre-existing, and preserved deliberately.** Raised by the cold
review of `feat/u6-bulk-run-screen` (U6 commit 4), which extracted this expression and did not change
it.

## The fact

`App\Support\CurrentTerm::forSchoolModel()`:

```php
return Term::query()
    ->where('academic_session_id', $session->id)
    ->where('status', 'active')
    ->first()                                   // ← no ORDER BY
    ?? Term::query()
        ->where('academic_session_id', $session->id)
        ->orderByDesc('order')                  // the fallback IS ordered
        ->first();
```

The **first** query has no `ORDER BY`. MySQL guarantees nothing about which row `LIMIT 1` returns from
an unordered result set, so a session holding two `active` terms resolves to one of them
arbitrarily — and can resolve to a different one between two reads of the same data, on nothing more
than a plan change.

Nothing prevents two active terms. `terms.status` carries no uniqueness constraint per session, and
the unique keys that do exist are `(academic_session_id, slug)` and `(academic_session_id, order)` —
neither of which mentions `status`. Advancing a term without retiring the previous one produces the
state directly.

## It is not new, and that is the point

This is the expression that stood inside `App\Http\Controllers\SetupController::index()` verbatim,
unordered in exactly the same way, for as long as that endpoint has existed. U6 commit 4 **moved** it
into the shared kernel so that its second consumer — the bulk-run screen's `default_term_id` — reads
one definition rather than a copy. The move preserved the behaviour on purpose: changing a resolution
rule while relocating it would make the relocation unreviewable, and the setup endpoint's callers have
been living with this answer.

What the move DID change is the blast radius. One consumer became two, and the second one **defaults
the term a bulk invoice run bills**. A wrong answer there is not a wrong number on a setup summary; it
is a cohort billed against the wrong term's price list. It is still only a DEFAULT — the screen shows
the term, keeps it changeable, and announces an override — so an operator can see and correct it. But
the failure mode moved from cosmetic to financial, which is why it is now written down.

## What would close it

Decide what "current" means when more than one term claims it, then say so in SQL. Two candidates:

1. **`->orderByDesc('order')` on the active query too**, matching the fallback directly below it. The
   later term wins, the two branches then agree with each other, and the whole method becomes "the
   latest term, preferring an active one".
2. **Refuse the ambiguity** — a school with two active terms in its current session has a data defect,
   and a resolver that silently picks is hiding it. Return null and let the caller ask, or surface it
   as a readiness finding.

Option 1 is one line and removes the nondeterminism. Option 2 is the better answer if two active terms
is genuinely never legitimate — but that is a question about term lifecycle, not about this method,
and nobody has answered it.

**Whichever is chosen, `SetupController` moves with it**, which is now automatic: it reads
`CurrentTerm` rather than its own copy. That is exactly what the extraction bought.

## Not covered by any test today

`tests/Feature/Finance/BulkInvoiceRunScreenTest.php`'s term-default arm seeds **one** active term per
session — the decoy sits in a different, non-current session — so it exercises the current-session
filter and says nothing about the two-active-terms case. A test for this belongs with the fix, since
today it would only pin whichever row MySQL happens to return.
