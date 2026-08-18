# U6 commit 2 — a fee schedule can say what a term bill contains

Branch `feat/u6-schedule-to-invoice-lines`, off `origin/staging` @ `2935b7e` (carries #258 and #259).

> **Cold review landed on `ec631d3`. Six findings and one ticket; all seven addressed in the follow-up
> commit. The response is in the *Cold review round 1* section at the end of this file, and the
> sections above it are corrected in place where they were wrong.** Two findings were ship-blocking and
> both were real: the mapper depended on ambient context and refused a foreign schedule under the wrong
> message (F1), and the "one rule, not two" claim about the status filter was false as written (F2).

One commit: the mapper, its tests, and a comment correction. **No job, no queue, no route, no screen** —
those are commits 3 and 4.

## What shipped

| File | What |
| --- | --- |
| `app/Finance/Services/FeeScheduleLineMapper.php` | new — `linesFor(FeeSchedule): list<InvoiceLineSpec>` |
| `tests/Feature/Finance/FeeScheduleLineMapperTest.php` | new — 9 tests, 27 assertions |
| `app/Finance/Actions/GenerateInvoice.php` | comment correction only, no behaviour change |

> **CORRECTED after review (F4).** This paragraph originally said `InvoiceLineSpec` "had exactly one
> construction site before this". It is built in **two** production places —
> `GenerateInvoiceRequest::lineSpecs()` (`app/Finance/Http/Requests/GenerateInvoiceRequest.php:366`) and
> `DriveFinanceStates` (`:260`, the local drive-fixture console command) — plus **27** sites under
> `tests/`. The claim was in the class docblock, the report and the commit message; the docblock and
> this report are corrected, and the commit message stands uncorrected on purpose (see F4 below).

`InvoiceLineSpec` was built from a request body a human typed into the bursar's modal
(`GenerateInvoiceRequest::lineSpecs()`) and from the drive-fixture command, and nowhere else on a
production path. `FeeScheduleLookup::activeFor()` returned a schedule that **nothing** turned into
lines. That narrower claim is the load-bearing one and it survives: this is the first thing that can
turn a fee schedule into a bill, and the first `InvoiceLineSpec` source on a production path that is
not a human typing.

## The mapper

Pure. No HTTP, no queue, no write, no `Money` arithmetic of its own — each item's `Money` value travels
onto its line unchanged, and the invoice total is still derived inside `GenerateInvoice`'s transaction.

- **Mandatory items only.** `finance_fee_items.is_mandatory` is a property of the PRICE LIST, not of a
  child; nothing in the schema records which student takes the bus or eats lunch, so a cohort run cannot
  know. Optional items are added per child afterwards, singly or as a supplementary invoice. Stated in the
  class docblock so the next reader does not "fix" it into billing everything.
- **Charge lines only.** `kind = InvoiceLineKind::Charge` on every line; `discountPolicyId` and `percent`
  are never set. U8's discount AWARD does not exist, so no reduction line has a fact to rest on — and
  keeping reductions out means `finance_invoice_lines_reduction_guard` is never *reached* from this path
  rather than being reached and satisfied by accident.
- **Deterministic order.** `sort_order`, then `id`. `sort_order` alone is not a total order: the column
  carries no uniqueness constraint and `CreateFeeSchedule` defaults it to the array index
  (`app/Finance/Actions/CreateFeeSchedule.php:70`), so ties are authorable and MySQL may return equal-key
  rows in any order.
- **Each line cites its item.** `feeItemId` is the INTEGER id — uuids are the wire convention (U8 commit 1
  made the generate endpoint refuse integers on the way in), and this mapper is server-side on both ends.
  `isDiscountable` is read from the item, never left to the DTO's `true` default.
- **It takes a `FeeSchedule`, not coordinates.** The caller pins one version for the whole batch;
  resolving inside would re-read per student, so an approval or supersession landing mid-batch could split
  one cohort run silently across two price lists.

The ordering is applied on the relation query rather than on whatever the caller eager-loaded — a
`->with('items')` upstream would otherwise decide this method's output order.

## FeeScheduleStatus ruling — ADMITTED: `active`. REFUSED: the other four.

Read from `app/Finance/Enums/FeeScheduleStatus.php`.

| Case | Ruling | Reason |
| --- | --- | --- |
| `active` | **admitted** | The one approved, current price list. |

> **CORRECTED after review (F2).** The `active` row originally read "This is the same single filter
> `FeeScheduleLookup::activeFor()` applies … so 'billable' stays one rule rather than two that can
> drift." **False as written.** The mapper tested `!== FeeScheduleStatus::Active` as PHP enum identity;
> the lookup tested `where('status', FeeScheduleStatus::Active->value)` as SQL. Same set, different
> layer, **no shared symbol** — two rules that happened to agree, which is precisely the shape #258's
> F2 landed on. The ruling and its reasons now live on `FeeScheduleStatus::billable()` and **both**
> sites read it; see *Cold review round 1 · F2*.
| `draft` | refused | Never approved. A draft is a proposal, not a price; billing one lets a Head price a term without the ED ever seeing it — precisely the failure the S1 approval path exists to prevent, and the reason `activeFor()`'s status filter is bite-proven by proof 26. |
| `pending_approval` | refused | Never approved, and *undecided*. Items are frozen by the three `finance_fee_items_parent_state_guard` triggers, which makes this state look closer to active than it is: a rejected publish returns the schedule to `draft` (enum docblock). Frozen is not approved. |
| `superseded` | refused | Approved once, since **replaced**. Only `ApproveFeeScheduleChange` may set `active`, and supersession happens there; a schedule in this state has been re-priced. Raising a cohort's bills from it prices a whole year group off a list the school has retired — silently, N invoices wide, which is the class of defect a bulk path must not make reachable. |
| `retired` | refused | Approved once, since **withdrawn**. Same shape as `superseded`, and withdrawal is the school saying explicitly that this list is not to be charged. |

The brief framed refusal (c) as "a schedule that was NEVER approved", which on its own admits only
`draft` and `pending_approval` as refusals. I refused the two post-approval states as well, and I want the
widening on the record rather than buried: `superseded` and `retired` *were* approved, so a narrower
reading is defensible — but the failure they produce is worse than the draft one, not better. A draft
billed in bulk produces a price nobody signed off; a superseded schedule billed in bulk produces a price
someone signed off **last time**, which reads as correct in every log and every invoice PDF and is caught
only when a parent complains. Admitting them would also put a second definition of "billable" in the tree,
disagreeing with `FeeScheduleLookup`'s.

## Comment correction — `GenerateInvoice.php`

The guard is unchanged. Only its stated reason was false, and this was the **third** file carrying it
(`BillableEnrollmentAdapter::findByUuid` was corrected in U6 commit 1).

The comment said `student_curricula` has no `school_id` and `StudentCurriculum` is deliberately unscoped.
Both halves are wrong today:

- `school_id` is **NOT NULL** with the composite FK `student_curricula_student_school_foreign
  (student_id, school_id) -> students (id, school_id)` —
  `database/migrations/2026_07_19_130000_add_school_id_to_student_curricula.php:80,:85-88`.
- `StudentCurriculum::booted()` registers a bare `SchoolScope` — `app/Models/StudentCurriculum.php:76-78`.

What actually makes the guard necessary, now stated at the site — three reasons, none covered by the scope:

1. **The scope is fail-OPEN for this model.** `SchoolScope::apply()` filters only when
   `ActiveSchool::id()` is truthy, and with no context it throws only for models listed in
   `rbac.fail_closed_models` (`app/Models/Scopes/SchoolScope.php:52-68`). That allowlist is the ten
   finance transactional models (`config/rbac.php:158-169`); `StudentCurriculum` is **not** among them. So
   off-request, or on any path with no School context, the uuid lookup runs unfiltered and a foreign
   episode resolves perfectly well. The null-context refusal in the Action is what closes that, not the
   scope.
2. **A scope filters; it never refuses.** Where it does apply, a wrong-School uuid becomes "not found" —
   indistinguishable from a typo. Isolation on a financial write is asserted so it can be reported as what
   it is.
3. **The lookup is behind a port.** `$this->enrollments` is `BillableEnrollmentProvider`; whether the
   adapter keeps or strips the ambient scope is that adapter's choice, and it already differs per method
   (`findByUuid` keeps it, the cohort reads strip it deliberately). An Action relying on the scope would be
   relying on the far side of a boundary it must not see through.

## Bite-proofs — every guard planted red, watched, restored

Each plant was a single edit to `FeeScheduleLineMapper.php`, the file restored from a copy afterwards
(final `diff` against the copy is empty).

**Baseline, all four guards in place:** `tests=9 passed=9 assertions=27`.

| # | Planted | Result | Failure |
| --- | --- | --- | --- |
| 1 | `->where('is_mandatory', true)` deleted | **red**, 2 failed | order/filter arm: `1 => 'Feeding'` became `1 => 'Transport', 2 => 'Feeding', 3 => 'Uniform'`; and the no-mandatory-items arm stopped throwing |
| 2 | the `$items->isEmpty()` refusal deleted | **red**, 1 failed | `Exception "App\Exceptions\BusinessRuleException" not thrown` |
| 3 | the mixed-currency refusal deleted | **red**, 1 failed | `Exception "App\Exceptions\BusinessRuleException" not thrown` |
| 4 | the non-`active` status refusal deleted | **red**, 4 failed | all four refused datasets (`draft`, `pending_approval`, `superseded`, `retired`) stopped throwing; `active` stayed green |
| 5a | `->orderBy('sort_order')` deleted | **red**, 1 failed | expected `['Tuition', 'Feeding']`, got `['Feeding', 'Tuition']` |

Plant 1's *two* failures are the point of that fixture: insertion order deliberately disagrees with
`sort_order`, so the filter arm and the order arm cannot satisfy each other.

### One plant that did NOT go red, reported as such

| # | Planted | Result |
| --- | --- | --- |
| 5b | `->orderBy('id')` deleted (the tiebreak) | **stayed green**, 9/9 |

With three items sharing `sort_order = 5`, MySQL returned them in insertion order anyway, so the tie test
passed without the tiebreak.

> **CORRECTED after review (F3).** This originally concluded "**The `id` tiebreak is therefore not
> bite-provable by removal on this engine.**" That generalised from three rows to the engine, and the
> reviewer disproved it by measurement: **16,384 mandatory items sharing one `sort_order`, default
> `sort_buffer_size`, MySQL 8.0.43 — removing the tiebreak produced 2 inversions.** The threshold is
> roughly **8k rows** at stock settings and roughly **1k** at `sort_buffer_size=32768`.

What is true: **three rows cannot show it.** Below the sort-buffer threshold MySQL's current plan
happens to return insertion order, so a small fixture is not evidence either way. The guard is
justified by MySQL guaranteeing nothing about the order of equal-key rows, and the measurement above is
what demonstrates that the absence of a guarantee is real rather than pedantic.

**No 16k-row test was added, deliberately.** It would pin one engine's sort-buffer behaviour at one
configuration rather than the ordering contract, and it would be slow and flaky right at the threshold.
The measurement is recorded here and in the ticket; the mapper's docblock cites the numbers.

## Tests

`tests/Feature/Finance/FeeScheduleLineMapperTest.php` — 9 tests, 27 assertions.

- maps only the mandatory items, in `sort_order` order, as charge lines citing their item — asserts the
  **ORDER** (`toBe` on an ordered list), the amounts, `kind`/`discountPolicyId`/`percent`/`note`, the
  integer `feeItemId` matched against the items themselves rather than a remembered order, and
  `isDiscountable` per item (`[true, false]`, so a DTO default would show as `[true, true]`)
- equal `sort_order` broken by `id`, twice over, same result both times
- refuses a schedule with no mandatory items, message naming the schedule uuid
- refuses mixed currency across the mandatory items, message naming the schedule uuid
- one dataset per `FeeScheduleStatus` case (5): `active` returns lines, the other four throw with the
  exact message

The fixture builds the schedule through `CreateFeeSchedule` (which always authors a draft, since the
parent-state triggers only admit item inserts into one) and moves the lifecycle with a raw status write —
the way the rest of the suite moves a lifecycle it is not the subject of.

## Gate

`bin/quality` — **PASS, 15/15**, base `2935b7e` (first commit `ec631d3`). Step 3 (`lint-changed`)
reported *"Pint (check) on 3 changed PHP file(s)"*, which is the whole diff: Pint was run against the
changed files by explicit path, never against a directory. Step 15 (`test-ratchet`) green against
`tests/ratchet-baseline.txt`.

The step count was re-derived from the run rather than carried: the script printed `[1/15]`…`[15/15]`.

The follow-up commit's gate result is under *Cold review round 1 · Gate*.

## Not built, deliberately

- No job, no queue, no HTTP route, no screen — commits 3 and 4.
- No `isBillable()` helper on `FeeScheduleStatus`. It would be the natural home for the ruling, but
  `FeeScheduleLookup` would then have to adopt it too, and that is a change to the live single-invoice
  prefill path from a commit whose scope is one primitive. The mapper's docblock names the lookup instead.
- No cross-School arm on the mapper. `FeeItem` carries `BelongsToSchool`, so the relation read is scoped
  by the model; isolation for the bulk path belongs to the job that resolves the cohort (commit 3), where
  the School is an argument rather than ambient.

---

# Cold review round 1

Six findings and one ticket, on `ec631d3`. All seven addressed in one follow-up commit. **I agree with
all six.** Two were ship-blocking (F1, F2) and both were defects I had reasoned past rather than
measured — F1 because I convinced myself `FeeItem`'s `SchoolScope` was doing isolation's job, F2 because
I wrote "one rule, not two" about two literals in two languages and did not ask what would turn both red.

## F1 — the mapper takes its School as an argument

**The finding, reproduced.** The reviewer probed the shipped mapper in both ambient states:

- **`ActiveSchool` = A, schedule from B** — `FeeItem`'s `SchoolScope` emptied the relation read and the
  mapper refused under *"has no mandatory items"*. Wrong reason. It sends an operator hunting a price
  list that is fine, and it labels an isolation failure as a pricing failure.
- **no ambient context, schedule from B** — the mapper returned **another School's lines**. `FeeItem`
  is not in `rbac.fail_closed_models`, so the scope is fail-open and simply did not filter.

**The change.** `linesFor(FeeSchedule $schedule, int $schoolId)`. The School is now the caller's
declaration of what is being billed, and two guards run before anything else:

1. `(int) $schedule->school_id !== $schoolId` → *"Fee schedule [uuid] belongs to another School; it
   cannot be billed for school#N."*
2. `ActiveSchool::id()` present and ≠ `$schoolId` → *"…cannot be billed for school#N from another
   School's context."*

**Why the second guard exists, and why it is not the ACL port's mechanism.** The brief said the
assertion alone makes the mapper "correct with or without ambient context, which is what the ACL port
already does". The first half does not follow from the assertion: the item read still carries `FeeItem`'s
`SchoolScope`, so a batch declared for A running under a stale B context passes guard 1, reads zero
items, and is refused as *"has no mandatory items"* — the same wrong-reason failure, one layer down.

The port closes this by stripping the scope and filtering explicitly. **`app/Finance` may not.**
`withoutGlobalScope(` inside `app/Finance/` is a HARD `finance-escape-hatches` failure
(`bin/ci-boundary-lint.php:159`, §17.1 rule 4), and the port's six calls are **baselined exceptions in
`app/Academics/`** — the lint's own header says the point of covering that directory is that "the
SEVENTH one has to be argued for". Copying the mechanism into Finance would be that seventh.

So the disagreement is **refused** rather than routed around. That leaves the item read exactly two
possible states — unscoped (no ambient context) or scoped to precisely `$schoolId` — which give the same
answer. The mapper is a function of its argument by exclusion instead of by escape hatch.

**Planted red — guard 1 deleted, both ambient states, exactly the two failure shapes the reviewer saw:**

```
--- FIX1 PLANT A: School assertion deleted        failed  19 tests  failed=2
  refuses a schedule belonging to another School … with data set "with the runner's own ambient context set"
      Failed asserting that 'Fee schedule [a288bbe7-…] has no mandatory items, so it cannot produce a
      term bill.' … contains "…belongs to another School…"          ← the WRONG-REASON refusal
  refuses a schedule belonging to another School … with data set "with NO ambient context at all"
      Exception "App\Exceptions\BusinessRuleException" not thrown.  ← the CROSS-SCHOOL LEAK
```

The assertion is on the **message**, not on the exception class, which is why plant A's first dataset
fails: a refusal arriving for the wrong reason is a failure here, not a pass.

**Planted red — guard 2 deleted:**

```
--- FIX1 PLANT B: ambient-agreement guard deleted     failed  19 tests  failed=1
  refuses to bill for one School from another School's ambient context
      Failed asserting that 'Fee schedule [a288bc0d-…] has no mandatory items, so it cannot produce a
      term bill.' contains "…from another School's context."
```

**Three new arms**, so neither guard can pass by refusing everything: the foreign-schedule refusal in
both ambient states (with the owner still able to bill the same schedule, in the same arm), the
context-disagreement refusal, and a positive arm that maps correctly with **no ambient context at all**
(`expect(ActiveSchool::id())->toBeNull()` first, so the arm cannot silently acquire one).

## F2 — the billable set has one home, read by both sites

**The finding.** The mapper tested `$schedule->status !== FeeScheduleStatus::Active` as PHP enum
identity; `FeeScheduleLookup` tested `->where('status', FeeScheduleStatus::Active->value)` as SQL. Same
set today, different layer, **no shared symbol**, nothing that could turn both red at once — while the
shipped docblock said "one rule, not two" and this report said "the same filter applies". Both false.

**The change**, on #258's F2 ruling — a shared *symbol*, not a shared *comment*:

- `FeeScheduleStatus::billable(): list<self>` — the set, with the per-case reasoning that used to live
  in two docblocks and a report table.
- `FeeScheduleStatus::billableValues(): list<string>` — the same set as column values.
- `FeeScheduleStatus::isBillable(): bool` — the in-PHP form.
- `FeeScheduleLineMapper` reads `$schedule->status->isBillable()`.
- `FeeScheduleLookup::activeFor()` reads `->whereIn('status', FeeScheduleStatus::billableValues())`.

**Planted red — `billable()` widened to `[Active, Draft]`. Four failures across three files, and the two
deciding sites are two of them:**

```
--- FIX2 PLANT: billable() widened to include Draft      failed  35 tests  failed=4
  FeeScheduleLineMapperTest  it bills from an ACTIVE schedule and refuses every other lifecycle state
      (draft dataset)   Exception "App\Exceptions\BusinessRuleException" not thrown.   ← THE MAPPER
  FeeScheduleLineMapperTest  it pins the contents of the billable set
      Failed asserting that two arrays are identical.
  FeeScheduleTest            proof 26 — the schedule lookup returns an ACTIVE schedule but NEVER a draft
      Failed asserting that App\Finance\Models\FeeSchedule Object … is null                ← THE LOOKUP
  FeeSchedulesScreenTest     SMOKE — store authors a DRAFT with items, the draft does NOT prefill …
      Failed asserting that Array &0 [ … ]                                    ← THE PREFILL ENDPOINT
```

That is the property the shared symbol exists to give and the pair of literals could not: **one edit,
both sites red**, plus the endpoint on top of them.

**One arm deliberately did NOT go red under that plant**, and it should not have: *"has the mapper and
the prefill lookup agree, per status, because both read the same set"* asserts **agreement**, and a
widened set keeps them agreeing. It is the coupling test; `it pins the contents of the billable set` is
the contents test. Reporting this so the green is not read as coverage it does not provide.

**Two new arms.** One pins `billable()`, `billableValues()`, and `isBillable()` case by case — stated per
case rather than as a count, so a sixth enum case added without a billability decision leaves the pin
unchanged and the next arm red. One asserts, for all five statuses, that `activeFor()` resolves the
schedule **exactly when** `isBillable()` says so and the mapper returns lines **exactly when** it does.

### Did the `FeeScheduleLookup` change move anything on the prefill path? No.

The brief said to stop and report if it did. It did not, and here is the evidence rather than the
assertion.

**The emitted SQL, both builders side by side** (temporary probe, run and deleted):

```
OLD: … where `term_id` = ? and `class_level_id` = ? and `status` = ?      -- [1,2,"active"]
NEW: … where `term_id` = ? and `class_level_id` = ? and `status` in (?)   -- [1,2,"active"]
```

Same three predicates, same three bindings, same single `"active"` value. `= ?` became `in (?)`; a
one-element `IN` is an equality test.

**The tests that cross that path, all green, unmodified**: `FeeScheduleTest`,
`FeeScheduleChangeTest`, `FinancePrefillRoundTripTest` (which POSTs prefill's response body into the
generate endpoint verbatim), `ReductionEnforcementTest`, `FeeSchedulesScreenTest`,
`EditFeeScheduleDraftTest`, plus the mapper's own file — **94 tests, 386 assertions, 0 failures**.
`activeFor()` has exactly one production caller, `FeeScheduleController::prefill` (`:178`).

## F3 — the tiebreak claim, corrected to what was measured

Corrected in place above (*One plant that did NOT go red*). Short version: I generalised from three
rows to the engine and the reviewer disproved it — **16,384 tied items, stock `sort_buffer_size`, MySQL
8.0.43, tiebreak removed → 2 inversions**; threshold ~8k stock, ~1k at `sort_buffer_size=32768`. Three
rows cannot show it. The guard stands on MySQL guaranteeing nothing about equal-key order, and the
numbers are now cited in the mapper's docblock and in the ticket. **No 16k-row test was added** — it
would pin one engine's sort-buffer configuration, not the ordering contract.

## F4 — the construction-site claim, in three places

`InvoiceLineSpec` is built at `GenerateInvoiceRequest.php:366` **and** `DriveFinanceStates.php:260`,
plus 27 sites under `tests/`. "Exactly one place in the application" was wrong in the class docblock,
this report, and the commit message.

- **Docblock:** corrected, and it now names both production sites and the test count.
- **Report:** corrected in place, with the strike recorded rather than silently edited.
- **Commit message `ec631d3`:** **stands.** Rewriting it would rewrite a sha the reviewer already
  holds, to hide a claim that was made and was wrong. The correction lives here instead.

The narrower claim survives and is the one that justified the commit: nothing could turn a fee
**schedule** into lines, so a bulk run had nothing to bill from.

## F5 — two qualifications on the `GenerateInvoice` comment

Both stated at the site.

- **The cited range was incomplete.** "Filters only when truthy" is `$schoolId = ActiveSchool::id()`
  then `if ($schoolId)` — `SchoolScope.php:48-50`. My `:52-68` named only the fail-closed branch
  (`:59-64`, resolved at `:93-102`) and omitted the half the sentence was actually about.
- **The allowlist is not a runtime guarantee.** `rbac.fail_closed_models` is
  `env('RBAC_FAIL_CLOSED_MODELS', …)` over a versioned default (`config/rbac.php:156-170`). The ten
  finance transactional models are what the **default** holds; an environment setting that variable
  replaces the whole list, which is its documented purpose (a per-environment retreat). The comment now
  reads the membership as "not in the default, and not guaranteed anywhere" — a second reason not to
  rest a financial guard on it.

## F6 — `CreateFeeSchedule` `sort_order` default is `:70`

Corrected in the report and in the mapper docblock, which now cites `CreateFeeSchedule.php:70`.

## Ticket — tie determinism is local to this mapper

`docs/handoff/tickets/fee-item-tie-order-differs-across-read-paths.md`

All four sites named: `FeeScheduleLookup.php:27`, `FeeScheduleController.php:67`,
`EditFeeScheduleDraft.php:90`, `CreateFeeSchedule.php:85` — each ordering items by `sort_order` alone.
For a schedule with tied `sort_order` the bursar's prefill can present an order the bulk run does not
reproduce: nothing is mispriced, but "the bill I previewed" and "the bill the batch raised" can list
their lines differently. The ticket carries the measurement, the four-line fix, the observation that
five sites repeating a two-key sort is the same duplication F2 just closed for the status filter, and an
explicit **do not add a 16k-row test**.

The mapper's docblock points at the ticket, so the next reader meets the divergence where it matters.

## Gate — follow-up commit

`bin/quality` — **PASS, 15/15**, base `2935b7e`. Step 3 (`lint-changed`) reported *"Pint (check) on 5
changed PHP file(s)"* — `FeeScheduleStatus`, `FeeScheduleLookup`, `FeeScheduleLineMapper`,
`GenerateInvoice`, and the test file, which is the whole PHP diff. Pint by explicit path, never against
a directory; Prettier was not run over `docs/`. Step 13 (`arch`) and step 7 (`boundary-lint`) green —
the latter matters here, because F1's fix deliberately did **not** reach for `withoutGlobalScope(`.

Full run of the changed area before the gate: mapper file **19 tests / 58 assertions**, and the
prefill-path sweep **94 tests / 386 assertions**, both clean.

