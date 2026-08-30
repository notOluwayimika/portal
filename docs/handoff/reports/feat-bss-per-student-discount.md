# feat/bss-per-student-discount — implementation report

**Base:** `staging` @ `a2ae203` (contains `fd5d8e5`, the scholarship-kind commit)
**Branch:** `feat/bss-per-student-discount` · one commit (amended) · **NOT pushed**
**Tier:** full review — money, migrations, DB triggers, `school_id` isolation, the maker-checker
governance path, a new enforcement point. **Recommend a cold session before merge.**

## Amend — the three cold-review defects are fixed

This report was rewritten after a cold review of `5464398` returned three ship-blocking findings.
What follows is the state after the amend; the findings and their fixes are summarised here and the
detail is folded into the sections below.

**FIX 1 — `base` had no writer, and the governance path silently dropped it.** `base` is now carried
end to end and, more importantly, is no longer something a caller decides.

- The chain had **TWO** whitelists, not one. The review named `ApproveDiscountPolicyChange::insertPolicy()`;
  `SubmitDiscountPolicyChange`'s `$proposed` array dropped it too, so fixing only the named one
  would have carried a column that was always null. Both moved, plus the change table (a new
  migration, `2026_08_26_130000`), the submit request rule, and **both read Resources** — a checker
  approving terms they cannot see is the same hole one screen earlier.
- **The amend now INHERITS the amended policy's base when the change does not state one.** Making
  `base` required on a percent basis was the obvious fix and is the weaker one: it moves the failure
  onto the maker remembering, and every amend where they forget still hands the family a bigger
  bill. Omission is instead made safe. A maker who wants to change the base still says so and is
  obeyed — both halves are pinned.
- **The design change: the base is now RESOLVED FROM THE POLICY inside `GenerateInvoice`**, by
  `resolveDiscountBase()`, mirroring `resolveDiscountability()` exactly — one query for every cited
  policy, keyed by id, caller's value overwritten. This is what makes the bulk run and the bursar's
  modal agree **by construction** rather than by two call sites each remembering to read the policy.
  An unresolvable policy id resolves DOWN to `discountable`, so a foreign or invented id cannot
  smuggle a wider base past it.

**FIX 2 — `reductionSpecFor()`'s throw escaped `bill()`'s catch.** The call sat one line above the
`try`, so it reached `attempt()`, which only logs: the student got **no run row at all**, the run
reported `completed`, and the cohort equality silently unbalanced while the docblock claimed a
`failed` row was written. The two lines moved inside the `try`. The new arm asserts the row, the
reason, that no invoice exists, **and the equality itself** — `billed + already + failed + sponsored
== cohort_count` — because asserting only the row would leave the run's own alarm untested.

**FIX 3 — the append-only exemption was argued on an audit trail that did not exist.** Two
mechanisms now carry it, and they are not redundant: `AwardStudentDiscount` writes a
`discount_award_created` entry in the SAME TRANSACTION as the award, carrying the RESOLVED terms
(percent, base, policy name — none of which is a column on the award, so nothing column-level could
ever record what the award costs); and `StudentDiscountAward` uses `LogsActivity`, which catches any
write that does **not** go through the Action — precisely the writer the exemption anticipates, the
next commit's import. The migration's sentence was rewritten to name both and to say what happens if
either is removed.

**One thing the review did not find and the gate did.** `PestNegatedExpectationMessagesTest` failed
on my own new arm: I passed a custom message to `expect($row)->not->toBeNull(...)`, which Pest
discards. It is now a positive `toBeInstanceOf` carrying the message, and the mutation was re-planted
to confirm the arm still reds — and now prints the sentence instead of an exported null. It did not
surface in `tests/Feature/Finance` because that gate lives in `tests/Feature/Quality`.

## Deviations and additions, first

Five things I did that the brief did not ask for. Each is argued below in place; they are
listed here so none of them is discovered in a diff.

1. **The `base` column is also made IMMUTABLE**, by the new `_bu` trigger, not by widening
   the existing `finance_discount_policies_update_guard`. `base` is a policy TERM and the
   table's whole stated invariant is "terms immutable, only status moves"; a term that
   escaped it through a column added after the guard was written would be that invariant
   made false by omission. The existing guard is untouched.
2. **A fourth award-time refusal: the policy's `basis` must be `percent`.** Not in the
   brief's list of three, and load-bearing: an `amount`-basis policy has `percent IS NULL`,
   so an award citing one produces a spec with neither an amount nor a percentage and
   `resolvedAmount()` raises a `LogicException` inside the run. The reduction guard cannot
   catch this — it inspects status, approval and School, never basis.
3. **The scholarship rule is enforced STRICTLY** — see "Decisions asked for" below.
4. **Three docblocks that this change made FALSE were corrected**, in
   `ProcessBulkInvoiceRun` ("the lines of a term bill do not vary by student"),
   `FeeScheduleLineMapper` ("the discount AWARD does not exist") and `ScholarshipKind`
   ("the reduction itself does not exist yet: no award, no policy link, no percentage").
   Leaving them would be a claim wider than its artifact, in the direction that reads as
   reassurance.
5. **Six citations into `GenerateInvoice.php` were re-derived**, twice — first `:479 → :517`, then
   `:517 → :576` after the amend added `resolveDiscountBase()`, plus `:256 → :263` for the
   `lockForUpdate` citation the second pass shifted. `citation-lint` (arch group) named each one.
   They are one-token changes; no prose in them was touched.

Four more from the amend:

6. **The read Resources expose `base`.** Not in the fix list I was given. A checker approves what
   `DiscountPolicyChangeResource` shows them, and "50%" reads identically whether it means half the
   tuition or half the whole bill — those are different amounts of money.
7. **`base` on the change table is OPTIONAL, not required on a percent basis.** Argued above; the
   inheritance is a mechanism and a required field is a rule with a human behind it.
8. **`SubmitDiscountPolicyChange` was changed too** — the second whitelist, which the review did not
   name.
9. **`insertPolicy()` takes the target MODEL rather than its id**, so it can inherit from it.

## What was built

**1 — `finance_discount_policies.base`** (`2026_08_26_110000`). `VARCHAR(16) NOT NULL
DEFAULT 'discountable'`, domain `discountable | total` held by a `BEFORE INSERT` /
`BEFORE UPDATE` trigger pair (`..._base_shape_bi` / `_bu`), following
`2026_08_26_100000_add_kind_to_scholarships_table.php` exactly: `SQLSTATE 45000`,
`COLLATE utf8mb4_bin`, `COALESCE(…, 0)`, no apostrophe, and an
`information_schema.TRIGGERS` read-back that throws rather than record a green that means
nothing. The `_bu` body carries the immutability arm as well.

**MESSAGE_TEXT lengths, read back out of the stored trigger bodies** (not counted off my
own source):

| trigger | message | chars |
|---|---|---|
| `..._base_shape_bi` | `finance_discount_policies: base must be discountable or total.` | **62** |
| `..._base_shape_bu` | `finance_discount_policies: base is a policy term and is immutable; only status may change.` | **90** |
| `..._base_shape_bu` | (also carries the domain message above) | **62** |

Both under the 128-character cap `2026_08_25_100000` measured.

**The existing immutable-terms update guard is not tripped, and I checked before writing
rather than after.** `ADD COLUMN` is DDL and fires no row trigger. The backfill is an
`UPDATE` setting only `base`; the guard compares `name`, `basis`, `value_minor`,
`value_currency`, `percent`, `requires_approval`, `school_id`, `uuid`,
`supersedes_policy_id` — every one NEW vs OLD, none of which moves. The backfill runs
BEFORE the `_bu` trigger is installed, or it would refuse its own migration.

**2 — `InvoiceLineSpec::$percentBase`**, nullable `?DiscountBase`, added last so positional
construction sites keep working. **A charge line cannot silently carry one because the
constructor throws** (`LogicException`, in the caller's own stack frame) when a base is
given with no percentage. The other direction is deliberately NOT an error: `percent` with
no base means the discountable base — today's behaviour — so the bursar's modal and the 27
existing test construction sites are untouched. `withAmount()` drops the base with the
percentage (keeping it would violate this class's own invariant on the line it just
resolved); `withDiscountable()` carries it.

**3 — `resolvePercentages()` reads the base PER SPEC.** Both sums (`$totalCharges`,
`$discountableCharges`) are folded once, in one pass, and SELECTED per spec — so two
percentage reductions on one invoice may sit on different bases. Rounding is untouched
(`Money::percentage`, banker's). The negation, the one-line-per-spec shape, and the
"only on a waiver or discount line" refusal are unchanged.

**One behaviour change, stated rather than slipped in:** the "needs at least one charge
line to reduce" refusal is now per spec. It used to fire whenever there was no
*discountable* charge line, because that was the only base. An invoice of purely
non-discountable charges plus a `total`-base percentage now resolves; the same invoice with
a `discountable`-base percentage still refuses, with the identical sentence.

**4 — `finance_student_discount_awards`** (`2026_08_26_120000`) + `StudentDiscountAward` +
`AwardStudentDiscount`. `UNIQUE(student_id)` — not `(school_id, student_id)`, which would
admit a second award for one student under another `school_id`. **Isolation is at the
engine on both sides**, by two COMPOSITE foreign keys onto `students (id, school_id)` and
`finance_discount_policies (id, school_id)`; `students.scholarship_id` is the cautionary
precedent one table away, whose non-composite FK is exactly the fault the run now has to
detect at run time. No column was added to `students`; the student is reached through
`BillableEnrollmentProvider::scholarshipIdsFor()`.

**5 — the run appends ONE reduction spec per awarded student**, inside `bill()`'s `try`, on a COPY
(`[...$lines, $reduction]`). `FeeScheduleLineMapper` is untouched and still receives no
student. Awards are preloaded for the whole cohort in ONE query with the policy eager-loaded
(`awardsForCohort()`), before the loop. A student with no award is passed `$lines` itself.

**6 — the governance path carries `base`, and the Action no longer decides it** (the amend).
`finance_discount_policy_changes.base`, nullable (a `retire` proposes no terms; an `amount` basis has
none), with its own `_base_shape_bi`/`_bu` trigger pair holding the domain and freezing the proposed
term — the same shape and the same three load-bearing pieces as the catalog's. `insertPolicy()`
resolves it `$change->base ?? <the amended policy's> ?? Discountable`. `GenerateInvoice::resolveDiscountBase()`
then overwrites whatever any producer supplied with the cited policy's own value, so the run and the
modal cannot disagree.

**7 — the award is audited** (the amend). `AwardStudentDiscount` wraps the create and a
`discount_award_created` entry in ONE transaction, so an unaudited award is not reachable; the entry
snapshots the resolved terms because `discount_policy_id: 41 → 57` cannot answer "did this child's
discount go up or down". `StudentDiscountAward` carries `LogsActivity` for every other writer.

## Decisions the brief asked me to take, and which I took

**A sponsored student's award: REFUSED — and so is anything that is not `kind = discount`.**
The brief's rule was "an award is only for a scholarship whose `kind` is `discount`", and I
enforced exactly that sentence rather than only its sponsored half. Three cases, one
reason: an award that can never be applied is configuration that reads as live.

- `sponsored` — the run excludes these students before it bills anyone, so their award is
  unreachable by construction. Storing one manufactures the confidence that a child is being
  discounted while an outside organisation is in fact being invoiced by hand.
- `kind IS NULL` — the run refuses the whole cohort until it is configured, so an award made
  now is unusable now.
- **no scholarship at all** — refused. This is the strictest of the three readings and the
  one most likely to be argued with, so it is flagged: **it puts an ordering constraint on
  the next commit** (the import must ensure the scholarship exists and its `kind` is set
  before awarding). I took it because a discount is a scholarship scheme at this school, and
  awarding one to a child on no scheme would be a fee change with nothing outside `finance_`
  recording why.

**A zero-total invoice: the system accepts it, and nothing downstream refuses it.**
Checked rather than assumed. `GenerateInvoice` refuses only a NEGATIVE total ("Reductions
may bring a total to zero, but never below it", ratified in accounting-policy.md §5).
`finance_ledger_transactions` has no positivity constraint, so a **zero-amount charge row is
written** and the balance moves by 0; `applyCreditForward` is capped at `min(credit, total)`
= 0 and writes no allocation. The run records the student as `billed`. **All four of those
are asserted in the 100%-on-total arm**, including the zero ledger row — so a future
positivity constraint on the ledger has to confront this arm rather than discover it in
production on a 100% scholarship. I did not have to work around anything.

**A policy retired AFTER the award is NOT filtered out of the preload.** Filtering would
look like tidiness and is the worst available behaviour: the child would be billed FULL
PRICE on a run reporting success. Left in, the line reaches
`finance_invoice_lines_reduction_guard`, is refused, and that one student gets a `failed`
row carrying the trigger's sentence while the rest of the cohort bills. Arm 5 pins this.

## Proof

**Totals are LITERALS, derived by hand.** Fixture: Tuition 1,000,000 `is_discountable=TRUE`,
Transport 400,000 `is_discountable=FALSE` — so `discountable base = 1,000,000` and
`total base = 1,400,000` and the two bases give different answers. A schedule whose items
were all discountable would give 900,000 either way and could not detect a resolver that
ignores the base at all.

| arm | reduction | invoice total |
|---|---|---|
| 50% discountable | −500,000 | **900,000** |
| 50% total | −700,000 | **700,000** |
| 100% discountable | −1,000,000 | **400,000** (the bus is still owed) |
| 100% total | −1,400,000 | **0** |
| 25% discountable | −250,000 | **1,150,000** |
| no award | — | **1,400,000** |

### Gates

```
php artisan migrate                → 2026_08_26_110000_add_base_to_finance_discount_policies       DONE (1s)
                                     2026_08_26_120000_create_finance_student_discount_awards      DONE (9s)
                                     2026_08_26_130000_add_base_to_finance_discount_policy_changes DONE (2s)
./vendor/bin/pest tests/Feature/Finance
                                   → passed  816 / 816   4,249 assertions   676s
./vendor/bin/pest tests/Feature/Finance/BssPerStudentDiscountTest.php
                                   → passed   28 / 28
php bin/ci-test-ratchet.php junit.xml
                                   → ratchet: OK — no new failures beyond the baseline (7 known-failing)   EXIT 0
./vendor/bin/pest (full, --log-junit)
                                   → 2,355 tests, 2,338 passed, 6 failed + 1 error, 10 skipped
php bin/ci-money-lint.php          → OK — no money-rule violations (0 known exception(s))
php bin/ci-boundary-lint.php       → OK — no new boundary violations (8 known temporary exceptions)
php bin/ci-authz-lint.php          → OK — no new commented-out authorization checks (0 known)
php bin/ci-citation-lint.php       → OK — no new citation violations (165 baselined key(s), 182 citation(s))
./vendor/bin/pest --group=arch     → passed 110 / 110
composer analyse (Larastan)        → passed, 0 errors
./vendor/bin/pint --test <19 changed files>  → passed
```

The 7 suite reds are all `ActivityLog` / `GuardianProfile` / `Auth` rate-limit — the known
cross-test-pollution family, none in Finance, all baselined. The ratchet is the authority and it is 0.

**Three gates went red during the amend and are recorded rather than tidied away.** Larastan fired
`nullsafe.neverNull` on `$supersedes?->base ?? …` (`??` already suppresses the null-property read, so
the `?->` is dead syntax) — resolved into an explicit `instanceof` above the array.
`PestNegatedExpectationMessagesTest` fired on my own new arm, described at the top.
`citation-lint` fired twice as `resolveDiscountBase()` shifted lines under six citations.

**The MESSAGE_TEXT lengths of the new change-table triggers**, read back out of the stored bodies:

| trigger | message | chars |
|---|---|---|
| `finance_discount_policy_changes_base_shape_bi` | `…: base must be discountable or total, or null.` | **77** |
| `finance_discount_policy_changes_base_shape_bu` | `…: base is a proposed term and is frozen once submitted.` | **86** |
| `finance_discount_policy_changes_base_shape_bu` | (also carries the domain message) | **77** |

Both under the 128-character cap. The catalog table's two (62 and 90) are unchanged.

### Watched reds — 22 planted regressions, each red on exactly the arms that name it

Each planted alone, run alone, restored from a copy held in a `mktemp -d` outside the repository.
**All 13 originals were re-planted after the amend to confirm none had gone green.** Restored tree:
28/28 green, `git status` clean of scratch.

The nine new ones:

| # | mutation | red |
|---|---|---|
| N1 | `insertPolicy()` drops `base` — the original defect verbatim | 2 arms: a school cannot author `total`; the amend loses it |
| N2 | no inheritance from the amended policy (`$change->base` only) | 1 arm — **only** the amend-without-stating half, which is the money defect exactly |
| N3 | `SubmitDiscountPolicyChange` drops `base` from the proposal | 2 arms — the second whitelist the review did not name |
| N4 | `resolveDiscountBase()` never called | caller's claim honoured; the run and the bursar path disagree (900,000 vs 700,000) |
| N5 | resolution falls back to the caller's claim | the ignored-base arm (`900000 !== 700000`) |
| N6 | the reduction build moves back OUTSIDE `bill()`'s `try` | the student's run row is null — cold review finding 2, reproduced |
| N7 | `LogsActivity` removed from the model | the either-side arm: no `updated` entry |
| N8 | the Action's explicit `->log()` removed | the causer/terms arm: no `discount_award_created` entry |
| N9 | the change-table base triggers not installed | the domain/freeze arm |

The 13 originals, re-run against the amended tree — same reds, same arms: base ignored (2), base
always total (4→6 now the new arms exist), award reused across the cohort (3→4), reduction never
appended (7→9), each of the five award-time refusals (1 each, 2 for the scholarship rule), the
catalog base domain (1), catalog base immutability (2), the policy composite FK (1), the DTO
constructor invariant (1). **Nothing came back green.**

N2 is the one worth naming: it reds **only** the arm where the maker says nothing. That is the
narrowest possible demonstration that the inheritance — not the maker, not the default — is what
stops the family being billed more.

### The one arm I had to rewrite because its first form proved nothing

"The backfill does not trip the immutable-terms guard" was first written as
`expect($affected)->toBe(1)`. MySQL returns rows *changed*, not rows *matched*, so a
base-only `SET base = 'discountable'` on a row already holding it returns 0 — and a silent
pass there is indistinguishable from a statement no trigger ever saw. The arm now runs the
same statement shape with a DIFFERENT value and requires it to be REFUSED, naming the base
trigger. Without that half it would have passed just as happily against a table with no
triggers at all.

## Things I did not do, and one that is now stale

- **No import columns, no screen, no way to create an award outside tests.** As instructed —
  `AwardStudentDiscount` has no route, no request, no gate.
- **`scholarships.kind`, the run exclusion, fee schedules, `FeeScheduleLookup`,
  `is_discountable`, `RbacSeeder`, the grants map and every permission are untouched.**
- **The award table is deliberately NOT append-only** — it is live configuration the next
  commit must be able to change. Its exemption is now argued on an audit trail that actually exists
  (FIX 3), and the migration says what happens if either mechanism is removed. This still makes the
  title of
  `SchemaConventionsTest::'finance_student_accounts is the ONE intentionally-mutable finance
  table'` **stale**. That test's assertions are all scoped to `finance_student_accounts` by
  hardcoded name, so it is green and I left it alone rather than edit an assertion I did not
  come to change — but the claim in its name is now false and someone should decide whether
  it widens or the award table gains guards.
- **Concurrency is not proven.** The one-award-per-student pre-check cannot hold under
  concurrency; `UNIQUE(student_id)` is what holds, and the raw-insert half of that arm is the
  only evidence for it. There is no `AwardConcurrencyTest`.
- **Still no gate on `AwardStudentDiscount`** — the reviewer's ticket-severity finding 5. It remains
  unreachable outside tests and tinker, and the permission lands with the controller in the next
  commit. Nothing fails a build the day a controller calls it without one; that is the open risk.
- **No screen was driven**, and `resources/js` was not touched — so the `base` field now returned by
  both Resources is not rendered anywhere. The API carries it; the discount-policies screen does not
  yet show it. That is the next commit's, but it means a checker using the CURRENT screen still
  cannot see the proposed base even though the payload now contains it.
- **The 5.7.23 asymmetry is reasoned, not measured.** Everything ran on local MySQL 8.x. Both new
  trigger pairs exist because production parses and ignores `CHECK`; that is this repository's own
  recorded fact, not something I verified on 5.7.
- **No drive.** No screen changed.
- **The first run of `2026_08_26_120000` aborted** on MySQL's 64-character identifier limit
  (1059) with the table created and the migration unrecorded — the documented
  aborted-migration hazard, hit live. The migration was rewritten to guard per OBJECT rather
  than per table (its first form would have SKIPPED every foreign key on the retry) and now
  reads its four constraints back and throws if any is missing. The retry converged; the
  final shape is pasted in the migration's own docblock reasoning and was verified with
  `SHOW CREATE TABLE`.
- **The dev database held 0 discount policies**, so the backfill statement touched no real
  rows there. Its behaviour is proven in the suite against a planted row, not on the copy.
