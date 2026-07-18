# Finance walking skeleton — convention report (template for future modules)

**STOP-for-review artifact.** This is the first `app/Finance/` code and the
template every future Finance slice + every future module copies. The code is the
vehicle; *this report is the deliverable*. Every structural choice below is a
**proposal** — reviewed and adjusted before slice 2 inherits it.

## What was built

One thin vertical, driven end-to-end through the real HTTP stack (tests/Feature/
Finance/WalkingSkeletonTest.php, 7 passing):

```
enrollment (ACL port) → invoice + line → ledger CHARGE
                      → payment + allocation → ledger PAYMENT (credit)
                      → cancel → ledger REVERSAL (credit); invoice row persists
```

Tables (all `fee_*`, RESTRICT referent FKs, `_minor`/`_currency` money, 1.4c
triggers in the creating migration): `fee_invoices`, `fee_invoice_lines`,
`fee_ledger_transactions`, `fee_payments`, `fee_payment_allocations`.

Stubbed as planned (recorded, not built): gap-free numbering (Sequences is the
gap-tolerant stub), approvals/maker-checker (Ph3), automated billing trigger
(manual entry point only — the fan-out convergence + `EnrollmentCreated` is 1.4e).

## The four guards — all bite-proven

| Guard | How proven | Result |
|---|---|---|
| Module boundary | temp `App\Finance` file importing `StudentCurriculum` → `pest --group=arch` | **fails** ("Expecting 'App\\Finance' not to use 'App\\Models\\StudentCurriculum'"); clean after revert (arch 11/11, Finance rules auto-activated) |
| ON DELETE RESTRICT | with one invoice, raw delete of the curriculum (CASCADE→enrollment) and of the enrollment | both throw `QueryException` at the DB |
| Ledger append-only | raw `DB::table` UPDATE and DELETE on a ledger row (what tinker/mass-delete do) | both throw `QueryException` (BEFORE UPDATE/DELETE triggers) |
| Money integrity | temp `'balance' => 'decimal:2'` on a Finance model → `ci-boundary-lint.php` | **1 NEW violation**; clean after revert |

Gates: Larastan **0**, pint clean (my files), boundary/authz/runtime-zero/identifier
lints OK, arch 11/11, full-suite ratchet unchanged (**15**).

## A. Did the module boundary feel natural or fought?

**Natural — and one constraint forced the right shape.** Arch rule 3 forbids *any*
`App\Finance` code from importing `StudentCurriculum`. That made it impossible to
put the ACL adapter inside Finance — which is correct: the adapter belongs to the
*provider* (Academics), not the consumer. So:

- **Port** (interface + DTO) lives in `App\Finance\Contracts` — Finance owns the
  shape, in Finance's language (`BillableEnrollmentProvider`, `BillableEnrollment`).
- **Adapter** lives in `App\Academics\BillableEnrollmentAdapter` — outside Finance,
  reads `StudentCurriculum`, builds the DTO.
- **Binding** lives in the composition root (`AppServiceProvider`), *not* Finance —
  so Finance never names a concrete Academics class; the dependency arrow is
  one-way (Academics → Finance's contract).

**Where I wanted to reach across and couldn't:** nowhere in the domain. The only
pull was the adapter needing academic reads — which is exactly what the port is
for, so the "friction" was the boundary doing its job. The SchoolScope on
`StudentCurriculum` also gave cross-School isolation *for free* (a cross-School
uuid resolves to null in the adapter).

## B. Did Money cross every layer cleanly?

**Yes.** `amount_minor` (int) on the wire → `Money::fromKobo` → `MoneyCast`
(`_minor`/`_currency` columns) → Resource → `{amount_minor, currency}` JSON. No
float, no `decimal:`, no raw model serialization anywhere; negation uses
`Money::times(-1)` (exact integer scaling). Asserted end-to-end in the test.

**Where the contract was awkward:** one spot — `$request->string()` returns a
`Stringable`, not a `string`, so passing it into a `string`-typed Action argument
is a type error; fixed with `(string) $request->input(...)`. Minor, but worth a
note in the module's controller conventions so slice 2 doesn't rediscover it.

## C. Was reversal-not-edit clean or fought?

**Clean.** Charge, payment and reversal are all ledger rows; cancellation posts a
reversing entry and never edits. Signed Money (charge +, credit −) makes
`balance = SUM(amount_minor)` trivial, and "cancel = post a −charge" is one line.

**The one nuance worth codifying:** *append-only ≠ immutable-status.* The ledger,
lines, payments and allocations are fully immutable (UPDATE+DELETE denied). An
**invoice** is un-deletable but its *status* legitimately mutates (issued →
cancelled), so it gets a DELETE-deny trigger only. The model layer mirrors this: an
`AppendOnly` trait (updating+deleting guard) on the truly-immutable models; Invoice
guards `deleting` alone. This distinction should be explicit in the module docs.

## The four structural proposals (made, built, held)

1. **ACL port + DTO** — interface + readonly DTO in `Contracts`; adapter outside
   Finance; bound in the composition root. **Held.** DTO = durable identities
   (enrollment/student/school ids) + snapshots (name, academic context), no Eloquent.
2. **Module folder shape** — matches `docs/module-blueprint.md` exactly: public
   `Contracts`/`Enums`; private `Models`/`Actions`/`Services`/`Http`/`Exceptions`.
   Added `Models/Concerns/` for the `AppendOnly` trait. **Held.**
3. **Service/application shape** — thin controller (validate→delegate→respond, no
   DB facade) → Action (`final`, one public `handle()`, owns one `DB::transaction`)
   → internal `SubledgerPoster` service (the single ledger writer). The arch rule
   "actions are final and expose handle()" fit with zero friction. **Held.**
4. **Invoice→ledger in one transaction** — the Action opens the transaction;
   `SubledgerPoster` never opens its own, so the ledger post commits atomically with
   the state change. A charge cannot exist without its invoice. **Held; natural.**

## Additional template decisions to REVIEW before slice 2 copies them

- **`fee_` table prefix** = the lint-enforced Finance-ownership marker (matches the
  existing `fee-table-outside-finance` lint and `ModuleClassificationService`'s
  `fee_invoices`/`fee_payments` reads). `fee_ledger_transactions` reads slightly
  oddly (a ledger row isn't a "fee"). **Decision needed:** keep `fee_` as the
  ownership marker, or adopt `finance_`? (If renamed, the lint pattern must follow.)
- **Uniform `school_id` on every Finance table**, including child tables
  (`fee_invoice_lines`, `fee_payment_allocations`) — denormalized so
  `BelongsToSchool` scopes identically everywhere (arch rule 5). The alternative
  was an `applySchoolScope`-through-parent override. Chose uniformity for the
  template. **Confirm.**
- **`@property` on models + `@mixin` on resources** = the module's Larastan
  convention. No existing model uses it (the legacy code baselines its ~924
  property errors instead); this keeps new Finance code at **0** without growing
  the baseline. **Adopt as the module standard?**
- **REF vs LOOKUP FKs** — durable referents (school/student/enrollment/invoice/
  payment) are live FKs, all `RESTRICT`; LOOKUP attributions (`cancelled_by_user_id`,
  `received_by_user_id`) and the polymorphic ledger `source_id` are plain columns,
  **not** FKs — so they never block a user's lifecycle and a reversal joins nothing.
- **Authorization** = route middleware `role:admin|super_admin` (inline `->hasRole(`
  is banned inside Finance by the escape-hatch lint — which is *why* authz sits at
  the edge). Finance Policies + SoD roles are Ph2/Ph3.
- **Error status** = `BusinessRuleException` → **422** uniformly. Align with the
  pending registrar error-code convention.
- **Route path** = `/api/finance/*` (unversioned, matching the existing surface).
  `/api/v1/finance/*` versioning (§16) is a Ph2 decision when the surface broadens.

## Future-phase check — does this shape preclude Ph3 / §13 / recurring billing?

**No redesign is forced. No STOP.** Verified each:

- **Maker-checker (Ph3):** additive. Invoices are created `ISSUED` today; adding
  `DRAFT → PENDING_APPROVAL → ISSUED` is an enum extension + an approval gate
  *before* the ledger charge posts (the Action's single transaction already gives
  the natural seam). Two things Ph3 adds, neither a redesign: a `created_by`/maker
  column on invoices (absent today), and **rejected drafts become a `REJECTED`
  status, never a delete** — which the DELETE-deny trigger *already* enforces, so
  the immutability model and maker-checker agree by construction.
- **GL export / Sage (§13):** additive. The subledger rows are typed
  (`LedgerEntryType`) and source-referenced (`source_type`/`source_id`); a GL
  journal is a periodic aggregation that *reads* the subledger into new tables. The
  subledger-only boundary holds; nothing about its shape changes.
- **Recurring billing:** additive. `GenerateInvoice::handle(enrollmentUuid, amount,
  description)` is caller-agnostic — the manual controller is one caller; a
  recurring scheduler or the 1.4e `EnrollmentCreated` listener is just another
  caller of the same Action. No change to the Action.

**One honest caveat for slice 2 (not a blocker):** the invoice `total` is a
snapshot equal to the single line's amount. With multiple lines, `total` must be
computed as `SUM(lines)` at creation (still a snapshot, but derived) — the Action
sums the lines. Slice 2 must not treat `total` as authoritative independently of
its lines.

## Proposed `app/Finance/` conventions (template — review before slice 2)

```
app/Finance/
├── Contracts/   PUBLIC  — ports Finance owns (interface + readonly DTO), no Eloquent
├── Enums/       PUBLIC  — vocabulary (InvoiceStatus, LedgerEntryType)
├── Exceptions/  private — LedgerImmutableException (defense-in-depth for the triggers)
├── Models/      private — BelongsToSchool + MoneyCast + @property; append-only via
│   └── Concerns/          the AppendOnly trait (immutable) or a deleting-guard (status mutates)
├── Services/    private — internal orchestration; the single writer of a shape (SubledgerPoster)
├── Actions/     private — final, one public handle(), owns ONE DB::transaction, no DB facade elsewhere
└── Http/
    ├── Controllers/ private — validate → delegate → respond; NO DB facade; authz at the route edge
    ├── Requests/    authorize()=true (route middleware gates) + rules() (amount_minor int on the wire)
    └── Resources/   @mixin the model; serialize Money via the VO ({amount_minor, currency})

ACL adapter → app/<Provider>/…  (OUTSIDE the consuming module; bound in AppServiceProvider)
migrations  → database/migrations/  (fee_* tables; RESTRICT referent FKs; 1.4c triggers in-migration)
routes      → routes/endpoints/finance.php → required into api.php inside an auth+role group
```
