# Finance module — frozen template (was: walking-skeleton convention report)

**FROZEN 2026-07-19.** This is the first `app/Finance/` code and the template
every future Finance slice + every future module copies. The proposals below were
reviewed and the template is now frozen — the ratified conventions are locked at
the top; the rest of the document is the original walking-skeleton report kept as
rationale.

## Ratified conventions (locked — every future Finance aggregate inherits these)

| Decision | Ratified | Enforcement |
|---|---|---|
| **Table prefix** | `finance_*` (was `fee_*`) | `finance-table-outside-finance` boundary lint keys on the prefix |
| **Tenanting** | every Finance table (incl. child tables) carries `school_id`, directly filterable | `SchemaConventionsTest` asserts the column on every `finance_*` table; arch rule requires `BelongsToSchool` on every model |
| **Child = parent `school_id`** | a child's `school_id` must equal its parent's; unrepresentable-when-violated | composite FK `(child_fk, school_id)` → `parent(id, school_id)` at the DB (bite-proven: mismatch rejected as a FK violation) |
| **API prefix** | `/api/v1/finance/*` | route file frozen; every aggregate hangs off it |
| **Larastan** | `@property` on models, `@mixin` on resources | Larastan level 5 (0 new errors; no baseline growth) |
| **Money** | VO + `_minor`/`_currency` + Resource-only wire (`{amount_minor, currency}`) | `decimal-money-cast` boundary lint; the `Money` VO / ADR 0037 |
| **Append-only** | ledger/lines/payments/allocations immutable; invoice DELETE-denied (status mutates) | 1.4c DB triggers (survive rename; verified by name) |
| **Validation error shape** | **DEFERRED** — pending the app-wide 422-vs-400 decision | not yet enforced; controllers currently 422 |
| **Invoice total = SUM(lines)** | computed once at creation, snapshotted, never hand-edited | **ENFORCED (slice 2).** Derived in `GenerateInvoice` from line specs — no wire field or Action parameter accepts a total; plus the `finance_invoices_total_immutable` BEFORE UPDATE trigger denying `total_minor`/`total_currency` edits. Multi-line proof uses 3 distinct non-round amounts. Residual GAP (post-creation line INSERT — tamper-only, not domain-reachable) and its closing mechanism are recorded under F6 in `docs/roadmap.md` |
| **Invoice lifecycle vocabulary** | `ISSUED` → `VOID` (the signed policy's word; never "cancelled", never a delete) | `InvoiceStatus` enum + a data migration; `Invoice::isVoid()`. The reversing-entry mechanism was already built in the skeleton |
| **One ACTIVE invoice per enrollment episode** | a *set*-based invariant — at most one non-void invoice per episode, re-billable after a void | STORED generated column `active_enrollment_key` + `UNIQUE(school_id, active_enrollment_key)` (F7 in `docs/roadmap.md`). Generated, not app-maintained |
| **Void exclusion is a READ-MODEL rule, not a global scope** | voided invoices drop out of reporting totals; they never drop out of existence | `Invoice::scopeExcludingVoid()` applied by `InvoiceReadModel`. A global scope was deliberately **rejected**: it would make route-model binding on `{invoice:uuid}` miss a voided invoice and turn the double-void 422 into a 404, destroying the guard |

Two items above are deliberately NOT active invariants yet: the 422-vs-400 shape
(deferred to the app-wide decision) and the total=SUM(lines) rule (a slice-2 gate
— the skeleton only has single-line invoices, so enforcing it now would assert
nothing). Both are recorded as pending, not promoted.

---

*Original walking-skeleton report follows (rationale for the choices above).*

## What was built

One thin vertical, driven end-to-end through the real HTTP stack (tests/Feature/
Finance/WalkingSkeletonTest.php, 7 passing):

```
enrollment (ACL port) → invoice + line → ledger CHARGE
                      → payment + allocation → ledger PAYMENT (credit)
                      → cancel → ledger REVERSAL (credit); invoice row persists
```

Tables (all `finance_*`, RESTRICT referent FKs, `_minor`/`_currency` money, 1.4c
triggers in the creating migration): `finance_invoices`, `finance_invoice_lines`,
`finance_ledger_transactions`, `finance_payments`, `finance_payment_allocations`.

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

## Template decisions — RATIFIED at the freeze (2026-07-19)

These were the open proposals; they are now decided (see the ratified table at the
top of this document and the Engineering Invariants in the roadmap):

- **Table prefix** → **`finance_*`** (renamed from `fee_*`). The
  `finance-table-outside-finance` lint keys on it; `ModuleClassificationService`
  and all tables/models/tests/routes moved over.
- **Uniform `school_id` on every Finance table** (incl. child tables) → **adopted**,
  and now DB-enforced: `SchemaConventionsTest` asserts the column, and a composite
  FK `(child_fk, school_id) → parent(id, school_id)` makes a child `school_id` that
  diverges from its parent's unrepresentable (bite-proven).
- **`@property` on models + `@mixin` on resources** → **adopted** as the module's
  Larastan standard (keeps new Finance code at 0 without growing the baseline).
- **REF vs LOOKUP FKs** → **kept**: durable referents (school/student/enrollment/
  invoice/payment) are live `RESTRICT` FKs; LOOKUP attributions
  (`cancelled_by_user_id`, `received_by_user_id`) and the polymorphic ledger
  `source_id` are plain columns, not FKs.
- **Authorization** → route middleware `role:admin|super_admin` (inline `->hasRole(`
  is banned inside Finance by the escape-hatch lint). Finance Policies + SoD roles
  are Ph2/Ph3 — unchanged.
- **Error status** → **DEFERRED**: `BusinessRuleException` → 422 for now, pending
  the app-wide 422-vs-400 decision (not frozen as an invariant).
- **Route path** → **`/api/v1/finance/*`** (frozen; §16).

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
migrations  → database/migrations/  (finance_* tables; RESTRICT referent FKs; 1.4c triggers in-migration)
routes      → routes/endpoints/finance.php → required into api.php inside an auth+role group
```
