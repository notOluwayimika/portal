# U6 commit 2 — a fee schedule can say what a term bill contains

Branch `feat/u6-schedule-to-invoice-lines`, off `origin/staging` @ `2935b7e` (carries #258 and #259).

One commit: the mapper, its tests, and a comment correction. **No job, no queue, no route, no screen** —
those are commits 3 and 4.

## What shipped

| File | What |
| --- | --- |
| `app/Finance/Services/FeeScheduleLineMapper.php` | new — `linesFor(FeeSchedule): list<InvoiceLineSpec>` |
| `tests/Feature/Finance/FeeScheduleLineMapperTest.php` | new — 9 tests, 27 assertions |
| `app/Finance/Actions/GenerateInvoice.php` | comment correction only, no behaviour change |

`InvoiceLineSpec` had exactly one construction site before this — `GenerateInvoiceRequest::lineSpecs()`
(`app/Finance/Http/Requests/GenerateInvoiceRequest.php:366`), from a body a human typed into the bursar's
modal — and `FeeScheduleLookup::activeFor()` returned a schedule that nothing turned into lines. This is
the second construction site, and the first one that is not a human.

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
  (`app/Finance/Actions/CreateFeeSchedule.php:69`), so ties are authorable and MySQL may return equal-key
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
| `active` | **admitted** | The one approved, current price list. This is the same single filter `FeeScheduleLookup::activeFor()` applies (`app/Finance/Services/FeeScheduleLookup.php:29`), so "billable" stays one rule rather than two that can drift. |
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
passed without the tiebreak. **The `id` tiebreak is therefore not bite-provable by removal on this
engine.** It is kept because MySQL guarantees nothing about equal-key row order — the observed order is a
current-plan artefact, not a contract — and a re-driven bulk run must not bill the same child a
differently-ordered invoice. Recording it here rather than letting the green imply a proof it did not give.

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

`bin/quality` — **PASS, 15/15**, base `2935b7e`. Step 3 (`lint-changed`) reported *"Pint (check) on 3
changed PHP file(s)"*, which is the whole diff: Pint was run against the three changed files by explicit
path, never against a directory. Step 15 (`test-ratchet`) green against `tests/ratchet-baseline.txt`.

The step count was re-derived from the run rather than carried: the script printed `[1/15]`…`[15/15]`.

## Not built, deliberately

- No job, no queue, no HTTP route, no screen — commits 3 and 4.
- No `isBillable()` helper on `FeeScheduleStatus`. It would be the natural home for the ruling, but
  `FeeScheduleLookup` would then have to adopt it too, and that is a change to the live single-invoice
  prefill path from a commit whose scope is one primitive. The mapper's docblock names the lookup instead.
- No cross-School arm on the mapper. `FeeItem` carries `BelongsToSchool`, so the relation read is scoped
  by the model; isolation for the bulk path belongs to the job that resolves the cohort (commit 3), where
  the School is an argument rather than ambient.
