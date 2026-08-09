# Implementation report — Constitution 13, enforced on the finance maker-checker Actions

## Headline

**Done.** All fifteen `Submit`/`Approve`/`Reject` actions in `app/Finance/Actions/` now refuse to act
without a School context, and refuse to act on another School's record, through one implementation
with a lint that keeps them there.

Branch `feat/finance-rule13-school-context-guard`, base `df097c8` (`origin/staging`, #223 merged).

**READ THIS FIRST. The failure-mode premise this commit was briefed on is WRONG, in both
directions, and I only know that because the brief asked for the unguarded behaviour to be observed
rather than predicted.** The guard is still worth having — but not for the reason either of us
gave, and the report says what it actually buys.

## The premise, corrected by observation

The brief predicted two modes: twelve actions **silently no-op**, and two commit a **cross-school
write**. I predicted the publish path was already closed by `SchoolScope`. All fifteen were run with
their guard removed, under a valid-but-wrong context, and the result is none of those descriptions:

```
SubmitCreditNote               RETURNED NORMALLY  WROTE: credit_notes 1->2
SubmitVoidRequest              UniqueConstraintViolationException  1062 Duplicate entry
SubmitFeeScheduleChange        ModelNotFoundException  FeeSchedule
SubmitDiscountPolicyChange     QueryException  3819 Check constraint 'finance_discount_…_terms_shape'
SubmitOpeningBalanceBatch      ModelNotFoundException  OpeningBalanceBatch
ApproveCreditNote              ModelNotFoundException  Invoice
RejectCreditNote               RETURNED NORMALLY  (wrote NOTHING — silent no-op)
ApproveVoidRequest             ModelNotFoundException  Invoice
RejectVoidRequest              RETURNED NORMALLY  (wrote NOTHING — silent no-op)
ApproveFeeScheduleChange       ModelNotFoundException  FeeSchedule
RejectFeeScheduleChange        ModelNotFoundException  FeeSchedule
ApproveDiscountPolicyChange    ModelNotFoundException  DiscountPolicy
RejectDiscountPolicyChange     RETURNED NORMALLY  (wrote NOTHING — silent no-op)
ApproveOpeningBalanceBatch     ModelNotFoundException  OpeningBalanceBatch
RejectOpeningBalanceBatch      ModelNotFoundException  OpeningBalanceBatch
```

**Three of fifteen silently no-op, not twelve.** All three are `Reject*` actions, and the reason is
structural rather than accidental: a reject writes a status and a reason onto a row it re-reads
under lock, and where that re-read is an `update()` on a scoped query rather than a `firstOrFail()`,
zero rows matched is zero rows updated — which returns cleanly.

**Nine of fifteen throw `ModelNotFoundException`.** That is the third mode, and it is now confirmed
rather than predicted: an ungraceful 500-class failure where an operator should get a refusal. The
guard does not close a hole on these nine; it converts an exception nobody can act on into a
sentence. That is worth having and it is not what the brief claimed.

**No cross-school mismatched row is achievable in these fifteen.** Both candidates are already
refused, by two different mechanisms I had to read the schema to find:

- `SubmitFeeScheduleChange` **retire**, against a target forced ACTIVE so the status check could not
  mask the result:

  ```
  B. SubmitFeeScheduleChange RETIRE (target forced ACTIVE), context = school#4,
     schedule belongs to school#3
     threw QueryException: SQLSTATE[23000]: 1452 Cannot add or update a child row
     fee_schedule_changes still 2
  ```

  The cause is a **composite foreign key**, not the scope:
  `finance_fee_schedule_changes_schedule_school_foreign (target_schedule_id, school_id) →
  finance_fee_schedules`. The row is stamped with the ACTIVE school and points at another school's
  schedule, and the database refuses the pair. **My prediction was wrong too** — I said publish was
  closed by `SchoolScope` and retire would write. Publish is indeed closed by the scope
  (`ModelNotFoundException` above), and retire is closed by the FK.

- `SubmitVoidRequest` reached its INSERT and was stopped by `1062` on the `open_key` unique index —
  a constraint that exists to enforce one open void request per invoice, catching this by accident.

**The one action that does write is `SubmitCreditNote`, and the write is not what "cross-school"
suggests:**

```
A. SubmitCreditNote, acting context = school#2, invoice belongs to school#1
   returned normally: App\Finance\Models\CreditNote
   credit_notes 1 -> 2
   NEW ROW  school_id=1  invoice_id=1  student_id=1  amount_minor=7777  status=submitted
   that invoice_id belongs to school#1
   => CROSS-SCHOOL WRITE: no
```

`SubmitCreditNote:69` stamps `'school_id' => $invoice->school_id`, so the row lands **wholly inside
the other school** and is internally consistent. There is no mismatched row to find afterwards —
which makes it worse to detect, not better: an operator whose active context was school#2 caused a
credit note to appear in school#1, authorised by nobody there, and it looks exactly like a
legitimate school#1 credit note.

### What the guard buys, per action — not averaged

| Outcome without the guard | Actions | What the guard changes |
|---|---|---|
| **Silent no-op** — returns as though it worked | `RejectCreditNote`, `RejectVoidRequest`, `RejectDiscountPolicyChange` | **Closes it.** This is the quiet defect the commit rests on. |
| **Write into another School** | `SubmitCreditNote` | **Closes it.** The only write the fifteen can commit. |
| **`ModelNotFoundException`** | the nine above | **Nothing is closed** — the scope already refused. Buys a sentence instead of a 500, and cover for the day a read stops going through the scope. |
| **Engine refusal** (1062 / 3819 / 1452) | `SubmitVoidRequest`, `SubmitDiscountPolicyChange`, `SubmitFeeScheduleChange` retire | **Nothing is closed** — a constraint already refused, two of them for unrelated reasons. Buys the same: a refusal instead of a driver error. |

**What closes it today, and this is a property of the CURRENT CALLERS rather than of the code:** every
HTTP path resolves these records through route model binding, which goes through `SchoolScope` and
filters correctly whenever the context is non-null. So HTTP is not the exposure. **Console runs,
queued jobs and direct construction are** — anything that hands a model in without resolving it.

## Deviations from the brief

**One, and it came out of the watched red.** The lint's allowlist started as a PERMISSION —
`SubmitDiscountPolicyChange` *may* call `require()` instead of `assertOwns()`. Watching it red in
both directions showed that rule was one-sided: stripping `require()` went red, but replacing it
with an unconditional `assertOwns()` stayed **green** — and that swap is a real defect, because a
`create` names no policy and `assertOwns()` would be handed nothing to own.

So the allowlist is now an **OBLIGATION**: that one file *must* call `require()`. Both directions
fire. A rule that cannot object to the wrong call in the one file it singles out says less than its
name.

## What changed

| File | What |
|---|---|
| `app/Support/SchoolContext.php` | **New.** `require()` + `assertOwns()`, one implementation. |
| `app/Finance/Actions/*.php` (15) | One conversion, two behaviour changes, twelve additions. |
| `bin/ci-boundary-lint.php` | `school-context-guard-missing`, zero baseline entries. |
| `tests/Feature/Finance/SchoolContextGuardTest.php` | **New.** 32 arms. |

### The diff is not "one conversion plus twelve additions"

Stated before anyone reads it: **`SubmitOpeningBalanceBatch` is the only one of the fifteen that
carried the complete guard.** Three imported `ActiveSchool`; only one compared `school_id`. So:

- **1 conversion** — `SubmitOpeningBalanceBatch`, both halves already present, messages unchanged.
- **2 BEHAVIOUR CHANGES** — `SubmitFeeScheduleChange` and `SubmitDiscountPolicyChange` had the
  null-context refusal and **no ownership refusal**. Their diff shows a **new sentence**, not a
  moved one. `SubmitFeeScheduleChange` in particular took a `FeeSchedule $target` carrying
  `school_id` and never compared it.
- **12 additions.**

### The three pre-existing messages are byte-identical

Asserted, not eyeballed — the helper renders them and the test pins all four strings:

```
No active School context: an opening-balance batch cannot be submitted.
No active School context: a fee-schedule change cannot be submitted.
No active School context: a discount-policy change cannot be submitted.
That opening-balance batch belongs to another School.
```

The article is derived from the noun so a caller passes one string rather than two that can
disagree; the crude vowel rule is pinned by those assertions rather than trusted.

### Why two names and not one nullable argument

A single `assertOwns(?Model $record, …)` would be shorter and is deliberately refused: it makes the
full guard and the weaker one identical at every call site, identical to the lint, and
indistinguishable to a reader. The next action to pass a nullable by accident would get half the
coverage and a green gate. Two names put the weaker case in the source.

`SubmitDiscountPolicyChange` is the one caller of `require()`. Its `create` kind names no policy —
the action refuses a create that does — so on that path there is **no pre-existing school-owned
record to belong to**. Verified rather than assumed: the only school-owned read (`:49`) is gated on
`$target !== null`, and the change row's own `school_id` is stamped from the context the call
returns. A complete guard over a smaller surface, not a partial guard over the same one. When a
target *is* named it calls `assertOwns` as well.

### ApproveOpeningBalanceBatch

Guarded on its own account, though `PostOpeningBalanceBatch` guards identically and it delegates
there. Its docblock says why: it stamps `decided_by_user_id`/`decided_at` **before** delegating, the
shared transaction rolls that back today, and *"covered by what it calls"* is a coincidence of the
current call graph rather than something the action guarantees.

## Proof

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance/SchoolContextGuardTest.php
{"tool":"pest","result":"passed","tests":32,"passed":32,"assertions":81}
```

15 actions × 2 cases (null context, foreign record), plus a coverage arm that compares the written
list against the filesystem glob, plus the message arm. **Every arm asserts the exception**, never
"nothing changed" — the whole point is that "nothing changed" is the symptom the guard exists to
distinguish from success, and three actions really do produce it.

### bin/quality — raw, unedited (ANSI colour codes stripped; nothing else removed)

```
quality gate — base df097c8

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

## The watched red

### The lint, both directions on the allowlisted file

```
DIRECTION 1 — require() stripped from SubmitDiscountPolicyChange
  ✗ school-context-guard-missing  app/Finance/Actions/SubmitDiscountPolicyChange.php
    does not call SchoolContext::require() on a live line — its create kind names no policy,
    so the context refusal is the only guard that path can carry (Constitution 13)

DIRECTION 2 — the create path SWAPPED to assertOwns (this stayed GREEN under the first rule)
  ✗ school-context-guard-missing  app/Finance/Actions/SubmitDiscountPolicyChange.php
    does not call SchoolContext::require() on a live line — …
```

### The lint on an ordinary action

```
guard DELETED (RejectVoidRequest)
  ✗ school-context-guard-missing  app/Finance/Actions/RejectVoidRequest.php
    does not call SchoolContext::assertOwns() on a live line — a finance governance act
    with no School-context refusal (Constitution 13)

guard COMMENTED OUT, not deleted (ApproveCreditNote)
  ✗ school-context-guard-missing  app/Finance/Actions/ApproveCreditNote.php
    does not call SchoolContext::assertOwns() on a live line — …
```

The commented-out case matters: it is the authz-rule-15 hole, and the rule reads live lines only.

### The unguarded behaviour

All fifteen, pasted above under *The premise, corrected by observation*. That survey is the most
valuable artefact this commit produced, and it is the one that says the brief was wrong.

## Deliberate remainder — not touched

`GenerateInvoice`, `RecordAccountPayment` and `PostOpeningBalanceBatch` already guard in bespoke form
on money-writing paths. Converting them is a separate decision and would have been smuggled into a
fifteen-file diff. **A fourth exists that the brief did not name:** `CreateFeeSchedule:37` carries a
null-context refusal and no ownership comparison. It is not a `Submit`/`Approve`/`Reject` action, so
it is outside both the fifteen and the lint's scope — recorded here so the boundary is a decision
rather than an oversight.

## Not done

- **No arm proves the guard fires before any other validation.** It is the first statement in each
  `handle()`, which is a source property; a reordering that put a status check first would still
  pass the dataset arm, because that arm only asserts the school message when the school is wrong.
- **The survey ran against one fixture shape per action.** A different record state could reach a
  different branch — the `SubmitFeeScheduleChange` retire case is exactly that, and needed the
  target forced ACTIVE before the interesting path was reachable at all.
- **`RBAC_FAIL_CLOSED_MODELS` was not set.** See below.

## Findings raised, not fixed

### Ticket — `RBAC_FAIL_CLOSED_MODELS` is empty everywhere, so `SchoolScope` is fail-open platform-wide

`config/rbac.php:78-81`:

```php
'fail_closed_models' => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('RBAC_FAIL_CLOSED_MODELS', '')),
))),
```

The default is `''`, so the list is empty in every environment and `SchoolScope` fails **open** for
**every** model in the system, not just the fifteen this commit guards. `SetSchoolContext:51` admits
a super admin with no active school at all, so a null context is reachable by a real principal.

**The evidence is the survey above**, and it cuts both ways honestly: nine actions were saved by a
`firstOrFail()` that happened to sit on a scoped read, and three were not — they returned success
having written nothing. That difference is not a design; it is which read shape each action happens
to use.

**Not set here, deliberately.** Populating it changes read behaviour across the whole platform and is
the owner's call, not a side effect of a finance commit.

### Others

- `SubmitCreditNote:69` stamps `school_id` from the invoice rather than the active context. That is
  what makes its unguarded write internally consistent and therefore undetectable after the fact.
  Worth knowing wherever else that pattern appears. **ticket.**
- The three `Reject*` actions no-op silently because their re-read is a scoped `update()` rather
  than a `firstOrFail()`. The guard covers them now, but the underlying shape — a write whose
  zero-row outcome is indistinguishable from success — is not unique to finance. **ticket.**
