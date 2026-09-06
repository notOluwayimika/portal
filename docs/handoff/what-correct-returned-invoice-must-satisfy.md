# What a correction to a returned invoice must satisfy

**A MEASUREMENT PASS.** This document designs nothing and proposes nothing. Every section is a
measurement against the working copy at `3bef263e` (`staging`), or it is marked UNKNOWN. No
application code was written; the one file created under `app/` was a bite-proof plant for §H and
was deleted in the same session (§H records the restore).

**Brookstone's settled requirements**, carried here so the constraints can be derived against them:

1. no Executive Director approval for a pre-release correction;
2. the corrected bill returns to Internal Audit for sign-off before the parent sees anything;
3. a full record: the reason for return, what the bill said before and says now, who changed it, when;
4. that history visible to Finance and Internal Audit only, never to the parent.

**One question stays open with them** — which accounting period a correction posts to. It is carried
as a named seam in §F and is NOT answered anywhere in this document.

## Corrections to the brief

| The brief said | The tree says |
| --- | --- |
| "I have already been wrong once about where `ApprovalRequirement` lives" | The brief's path is **correct**: `app/Finance/Approval/ApprovalRequirement.php` exists, 52 lines. Nothing to correct. |
| "I believe the reversal [`effective_at`] is decided in one place; verify" | **Confirmed, one place** — but the neighbouring claim needs splitting. A *void's* reversal is decided at exactly one site; a *newly raised charge* is decided at **two**. See §F. |
| "finance_invoices.return_reason" | **Correct** — `2026_09_04_100000_finance_invoices_return_to_finance.php:182`, nullable `VARCHAR(255)`. |
| "the activitylog entries ReturnInvoice and ApproveInvoice write" | **Correct**, both exist and both write. `app/Finance/Actions/ReturnInvoice.php:214-227`, `app/Finance/Actions/ApproveInvoice.php:197-208`. |

## CORRECTION, 2026-09-06 — requirement 4's readership

**This document is merged and this part of it is wrong. It is corrected here rather than edited
away, because the METHOD that produced each error is the reusable part.**

**Measured at `97555b24` (`origin/staging`), and the base matters.** An earlier pass of this
correction was measured at `f22e86fb`, this document's own merge commit, and every number in it was
stale within a day: `97555b24` carries PR #420, which adds a SIXTEENTH role, `admin_viewer`, holding
four of the seven activity-log abilities. Nothing in the earlier pass's coverage numbers could have
revealed that — `tests/fixtures/route-access-map.json` holds **384 routes at both commits**, so
*"384 routes EXAMINED"* was byte-identical before and after while 121 of its role lists moved. **A
denominator that holds while the content moves is not a freshness check.** Any number below is to be
re-derived against the base you are reading at, and the base is stated so that is possible.

### What it said

Four places in this document say the same thing, and each now carries an inline
**[CORRECTED 2026-09-06]** marker pointing back here: the requirement-4 row of the §E status table;
the paragraph headed *"The requirement-4 finding, stated plainly"*; the *"Finance and Internal Audit
only"* paragraph under §"Requirement 4, both halves"; and finding 13 under THE CONSTRAINTS. They are
named by heading rather than by line number, because a line number inside the document it cites goes
stale the moment the document is edited — which it did, twice, while this block was being written.

> **No Finance role holds either** — not `accounts_officer`, not `finance_lead`, not
> `accounts_supervisor`, not `executive_director`, not `principal`.
>
> […] so Finance **cannot read the return history at all**; and `teacher` holds
> `activity_log.view`, so a role that is neither Finance nor IA can read the API.

### What is true — three corrections, one to each clause

**(1) The grant measurement was right. The CAPABILITY conclusion drawn from it was not.**

No Finance role holds any `activity_log.*` ability. That stands, at both bases. **But there is a
dedicated Finance-facing surface that carries the reason, the returner and the timestamp:**

| surface | gate | reaches it (`tests/fixtures/route-access-map.json`) |
| --- | --- | --- |
| `GET /finance/returned-bills` (`routes/web.php:355-357`) | `permission:finance.invoice.generate` | `accounts_officer`, `admin`, `super_admin` |
| `GET /api/v1/finance/invoices/returned` (`routes/endpoints/finance.php:495-496`) | same | same |

`app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php:190-192` puts `returned_at`,
`returned_by` (a NAME, via `App\Finance\Services\ActorName`) and `return_reason` — *"passed whole and
untruncated"* — into that payload.

**So the correct sentence is a SPLIT, and it names SEATS rather than a department:**

- **CURRENT STATE — `accounts_officer` HAS it** (with `admin` and `super_admin`). It is one Finance
  seat of four: `finance_lead`, `accounts_supervisor` and `executive_director` reach neither door and
  hold no activity-log ability, so for them the original sentence is exactly right. Saying *"Finance
  has the current state"* would generalise past the artifact, which is the same defect one level down
  as the one being corrected.
- **HISTORY — no Finance seat has it.** The activity row is the only history, and no Finance role can
  read the log.

**What the queue's filter actually does, stated at its measured size.** The filter is
`whereNull(Invoice::RELEASE_STAMP_COLUMN)` / `whereNotNull('returned_at')` / `excludingVoid()`
(`app/Finance/Http/Controllers/ReturnedInvoiceQueueController.php:162-164`;
`RELEASE_STAMP_COLUMN = 'reviewed_at'`, `app/Finance/Models/Invoice.php:237`). Of the three ways a
bill could leave that screen, **only one exists today**:

| exit | reachable? |
| --- | --- |
| **voided** | **YES.** No void path reads `returned_at`; `excludingVoid()` removes the row. |
| corrected / resubmitted | **NO — the verb does not exist.** `ReturnedInvoiceQueueController.php:32-35` says so: *"No correction, no resubmission, no state change […] whether a correction clears `returned_at`, whether it stamps a second column, whether it is a new bill entirely"* is an open Brookstone question. `grep -rn "'returned_at' =>" app/` finds ONE writer, `app/Finance/Actions/ReturnInvoice.php:192`, and it writes `now()`. Nothing writes NULL. |
| re-released | **NO — refused, in both directions.** `app/Finance/Actions/ApproveInvoice.php:273` (`refuseIfOutWithFinance`), called at `:163` and `:185`, with `->whereNull('returned_at')` in the compare-and-swap at `:174`. |

So the loss of history is real but its mechanism is narrower than *"a bill leaves the screen when it
is corrected"*: **today a bill leaves only by being voided.** The presence of a predicate in a filter
licenses *"this filter could exclude X"* — never *"X happens"*.

Likewise `app/Finance/Actions/ReturnInvoice.php:222-224` — *"a second return overwrites the column,
and this row is then the only place the first return's instruction exists"* — is a FORWARD-LOOKING
statement. A second return is refused today: `refuseIfAlreadyReturned` at
`app/Finance/Actions/ReturnInvoice.php:279`, called at `:182`, with `->whereNull('returned_at')` in
the CAS at `:189`. It is the same status the same codebase gives its own release filter, *"a BELT
rather than a working exclusion today"*.

Requirement 4's Finance half is therefore **partially satisfied for one seat**, not unsatisfied — and
the part that is missing is precisely the part requirement 3 calls *"a full record"*. Open question
12 under §"Open questions" (*"Does requirement 4 need an RBAC change or a different surface?"*) had
already listed *"accept that Finance sees the current-state columns only and not the history"* as an
option, so the option was seen; the flat sentence earlier is what overstated it.

**(2) The `teacher` clause is wrong, and the CLAIM it was supporting is right for other roles.**

*"A role that is neither Finance nor IA can read the API"* is true of the DOOR and false of the ROWS,
for `teacher` specifically — traced below: `teacher` lacks `view_all`, so it sees only rows it
caused, and it can cause no finance act.

**But the underlying claim survives, through roles the passage did not name.** `admin`,
`head_of_school` and — since `97555b24` — `admin_viewer` all hold `activity_log.view_all`, so none of
them is self-filtered, and none is a Finance or an Internal Audit seat. **`admin_viewer` is the
sharpest instance**, because it is a NEW read-only oversight seat that also holds
`activity_log.view_sensitive`, and it is derived rather than hand-listed
(`database/seeders/RbacSeeder.php:181-184`):

```php
$map['admin_viewer'] = [
    ...ReadOnlyAbility::filter($map['admin']),
    PermissionEnum::ADMIN_AREA_VIEW->value,
];
```

`RbacSeeder.php:167-170` states the outcome deliberately: `activity_log.export` falls out, and
*"`activity_log.view_sensitive` IS admitted: it is a read, and the seat is an oversight seat."*

So finding 13's *"visible to one it excludes"* half is **correct as a claim and wrong in its
example**. Fix the example, keep the finding.

**(3) The method error, which is the reusable part.**

**A gate measurement answers "who holds this permission". It does not answer "who can see this
fact".** The original finding measured the activity log's two doors correctly and then drew a
conclusion about a CAPABILITY, which is a different object: a capability is satisfied by any surface,
and the search for other surfaces was never run. This is *presence is not reachability* with the sign
flipped — an absence at one door read as an absence everywhere — and it is the shape CLAUDE.md warns
about, where a claim of ABSENCE requires an exhaustive search and the memory of not having seen a
thing is not that search.

The search that closes it is cheap, and it is on the COLUMN rather than on the permission:

```bash
grep -rn "return_reason\|returned_at\|returned_by_user_id" app resources/js
```

73 hits, of which `ReturnedInvoiceQueueController` is 15.

### AND THE MAP-VERSUS-DATABASE DISTINCTION, WHICH IS NOW LIVE — IN THE OPPOSITE DIRECTION

The brief that commissioned this correction suspected the original numbers were measured against the
wrong object — `RbacSeeder::grantsMap()` (intent, in code) rather than the live grants (reality, in
the database) — because `RbacSeeder.php:624` names a convergence migration,
`2026_09_01_120000_grant_internal_auditor_activity_log_view_all`, that grants to an existing role.
**That specific reasoning is false: `:625`, the line the comment annotates, IS
`PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value`, inside the `internal_auditor` block opening at
`:610`.** The grant is in the map. The convergence migration exists because `rbac:sync` is
non-destructive for an already-existing role, not because the map lacks it.

**The suspicion was nonetheless right, and the direction is the surprise: HERE IT IS THE DATABASE
THAT IS STALE.** `php artisan rbac:diff-grants` at `97555b24`:

```
SECTION A — permission catalog (enum vs `permissions` rows)
 1 declared in the enum, NO permission row:
 - admin_area.view

 BANNER: the catalog does not agree with the enum, which means `rbac:sync` has NOT been
 run on this database. SECTION B IS UNINTERPRETABLE until it is [...]

 1 role(s) in grantsMap() have NO global role row — rbac:sync would create them:
 - admin_viewer

FOOTER — school-scoped `web` role rows (school_id IS NOT NULL), counted, NEVER diffed: 0
TOTALS catalog: 1 missing row(s), 0 extra row(s) | grants: 0 missing, 0 extra across 0 role(s) | roles: 1 mapped-without-row, 0 unmapped
```

Exit **1**. So on the machine this was measured from, `admin_viewer` exists in the seeder and not in
the database, because nobody has run `rbac:sync` since PR #420 landed. **`rbac:sync` was NOT run** —
it is a mutation of a production-derived copy and it is the maintainer's call, not a measurement
step. (The catalog diff is `missing_rows` only, `1 missing / 0 extra`, which by
`docs/runbooks/rbac-grants-reconciliation.md` §2a is the safe case; that is information for whoever
decides, not a licence taken here.)

**Which is authoritative for "who can read the return history"?** The TREE — `grantsMap()` and the
two derived oracles — because that is what every environment holds after a deploy, and it is what the
oracles are regenerated from. A database mid-deploy is one environment's transient state. Both are
reported below, separately and labelled, because collapsing them is exactly how a stale number
becomes a confident assertion.

**Keep the general rule and note where it does bite.** Three migrations touching `activity_log`
grants (`2026_08_02_100000`, `2026_08_04_100000`, `2026_09_01_120000`) each operate on GLOBAL rows
only and each explicitly reports school-scoped rows as UNTOUCHED, and `roles` is team-scoped — so a
school-scoped role row can carry grants no map describes. `rbac:diff-grants` counts those and refuses
to diff them (`app/Console/Commands/RbacDiffGrants.php:45-46`). Here that count is **0**.

## THE MATRIX, RE-MEASURED — 2026-09-06, at `97555b24`

**INTENT — `RbacSeeder::grantsMap()`, executed. 16 roles. This is the authority.**

```
ROLE                  view  view_all  view_own  view_system  view_cross_school  export  view_sensitive
admin                  YES     YES       YES         .              .            YES        YES
head_of_school         YES     YES       YES         .              .            YES        YES
teacher                YES      .        YES         .              .             .          .
registrar               .       .         .          .              .             .          .
guardian                .       .         .          .              .             .          .
principal               .       .         .          .              .             .          .
boarding_parent         .       .         .          .              .             .          .
key_stage_coordinator   .       .         .          .              .             .          .
form_teacher            .       .         .          .              .             .          .
accounts_officer        .       .         .          .              .             .          .
executive_director      .       .         .          .              .             .          .
accounts_supervisor     .       .         .          .              .             .          .
finance_lead            .       .         .          .              .             .          .
internal_auditor       YES     YES        .          .              .            YES         .
super_admin             .       .         .         YES            YES            .          .
admin_viewer           YES     YES       YES         .              .             .         YES

HOLDERS / 16   view=5  view_all=4  view_own=4  view_system=1  view_cross_school=1  export=3  view_sensitive=3
POSITIVE CONTROL  finance.access                     roles=6
ABSENT   CONTROL  activity_log.zzz-no-such-ability   roles=0
```

**REALITY — `role_has_permissions` on `db=portaa10_portal`. 15 roles, 15 global, 0 school-scoped.**
Identical to the block above except that **the `admin_viewer` row does not exist**, so
`view`/`view_all`/`view_own` read 4/3/3 and `view_sensitive` reads 2. `model_has_permissions` holds
**0 rows in total**, so nobody holds an activity-log ability outside a role. The delta is exactly the
one `rbac:diff-grants` names above; it is a deploy that has not happened on this machine, not a
finding about the tree.

**Coverage, three numbers.** EXAMINED: 16 of 16 mapped roles (15 of 15 in the database) × 7 of 7
`activity_log.*` enum values (`app/Enums/Permission.php:55-61`). EXCLUDED with a stated reason: **1**
— the `api`-guard `super_admin` row (`RbacSeeder.php:19-21`), which carries no web grants and is
outside `guard_name = 'web'` by construction. **UNRECOGNISED: 0** — a query for `permissions` rows
named `activity_log.%` outside the seven returned an empty set, asserted directly rather than
inferred from a clean run.

**Two things no grant table sees, checked separately so neither is a silent gap:**

- **Direct user grants** — `model_has_permissions` is empty, measured above.
- **`Gate::before`, and it is CONDITIONAL** — `super_admin` holds neither `view` nor `view_all` and
  reaches BOTH doors anyway. The mechanism is
  `app/Providers/AppServiceProvider.php:127` (`registerSuperAdminGate`), whose bypass arm at `:132`
  is `config('auth.gate_before_superadmin') && $user->isSuperAdmin()`. `RbacSeeder.php:268` is a
  COMMENT recording the intent — *"super_admin deliberately gets none: its passage is
  Gate::before"* — not the mechanism, and citing it as the mechanism was a coordinate error
  corrected on 2026-09-06.

  **The flag matters, and it is why this bullet says "conditional".**
  `config/auth.php:133` defaults it to `true` via `AUTH_GATE_BEFORE_SUPERADMIN`, so it is
  environment-overridable. `app/Support/RouteAccessMap.php:24-26` states the oracle's dependency on
  it outright — super_admin is admitted *"only while auth.gate_before_superadmin is on […] flag-off
  admits only actual holders"*. So **with the flag off, `super_admin` reads NOTHING on either audit
  door**, holding neither ability. The row below is written for the default.

  This is also why the grant matrix and the reachability oracle disagree by one role, and the oracle
  is the one that answers the question asked.

**Environment caveat, bounding what the database half can support.** This database has replayed
**179 of the tree's 181** migrations. The two unrun are
`2026_09_04_100000_finance_invoices_return_to_finance` and
`2026_09_04_110000_finance_invoices_auditor_queue_index` — both schema-only and neither touching
roles, permissions or grants. `finance_invoices` holds 0 rows and `activity_log` holds 180,952 of
which **0** carry `log_name = 'finance'`, so this machine is not a source of finance ROW facts, and
`returned_at` does not exist on it at all.

## WHICH SEATS CAN READ THE RETURN HISTORY — 2026-09-06, at `97555b24`

The return history is `activity_log` rows with `log_name = 'finance'` and
`event = 'invoice.returned'` (`app/Finance/Actions/ReturnInvoice.php:214-227`). Reading one takes
THREE things, and a matrix of grants answers only the first.

**1 — the door.** `tests/fixtures/route-access-map.json` — 384 routes EXAMINED, 11 matched
`activity-log`, absent control `zzz-no-such-route` = 0, positive control `/api/` = 276:

| door | gate | roles that reach it |
| --- | --- | --- |
| `GET /api/activity-logs` and siblings | `activity_log.view` (`routes/api.php:386`) | `admin`, `admin_viewer`, `head_of_school`, `internal_auditor`, `super_admin`, `teacher` — **6 of 16** |
| `GET /activity-logs` (the page) | `activity_log.view_all` (`routes/web.php:1081`) | `admin`, `admin_viewer`, `head_of_school`, `internal_auditor`, `super_admin` — **5 of 16** |
| `GET /api/activity-logs/export` | `activity_log.export`, INTERSECTED with the group gate (`routes/endpoints/activity-log.php:25`) | `admin`, `head_of_school`, `internal_auditor`, `super_admin` — **4 of 16**; `admin_viewer` is refused here |

**2 — the row filter.** `app/Services/ActivityLog/ActivityLogQueryService.php:54-57`:

```php
// No view_all → users only see activity they themselves caused.
if (! $user->can('activity_log.view_all')) {
    $query->where('causer_type', User::class)
        ->where('causer_id', $user->id);
}
```

Every read method on `app/Http/Controllers/ActivityLog/ActivityLogController.php` goes through
`baseQuery` — `index:56`, `show:81`, `filterOptions:118`, `stats:166`, `export:230`. The one that
does not, `downloadExport:285`, serves an already-created `Export` and is gated by `ExportPolicy` on
permission AND owner. There is no second path into the rows.

**3 — the sensitivity filter.** `config/activity_log_sensitive.php:27-49` declares **12** entry
patterns. Two begin `finance.` — `finance.fee_adjusted` (`:30`) and `finance.refund_issued` (`:31`) —
and the only wildcard is `permissions.*` (`:41`). `finance.invoice.returned` matches none of the
twelve, so `excludeSensitive` does not hide it from a seat lacking `view_sensitive`.

### The answer

| seat | can it read the return history? |
| --- | --- |
| `internal_auditor` | **YES, in full.** `view` (the feed), `view_all` (so `:54-57` does not self-filter it) and `export`. It lacks `view_sensitive`, which costs it nothing here because the return event is not a declared sensitive entry. Requirement 4's IA half, satisfied. |
| `accounts_officer` | **NO for the history** — zero activity-log abilities. **YES for the current return**, on `/finance/returned-bills`, until the bill is voided. |
| `accounts_supervisor`, `finance_lead`, `executive_director` | **NO, on both counts.** No activity-log ability and no returned-bills door. |
| `admin`, `head_of_school` | YES, in full, plus `view_sensitive` and `export`. Neither is a Finance nor an IA seat. |
| `admin_viewer` (new at `97555b24`) | **YES, in full, plus `view_sensitive` — and NOT `export`.** A read-only oversight seat, neither Finance nor IA, that can read every return on both doors. This is the live instance of requirement 4's *"never to anyone else"* concern, and it arrived after this document was merged. |
| `super_admin` | YES **while `auth.gate_before_superadmin` is on** (its default), by `Gate::before` (`app/Providers/AppServiceProvider.php:127`, bypass arm `:132`) and not by grant. With the flag off it holds neither ability and reads nothing. Bounded to the active school by `SchoolScope` either way (ADR 0036). |
| `teacher` | **Reaches the API door and can see NO finance row.** Traced below. |
| `guardian`, `principal`, `registrar`, `form_teacher`, `boarding_parent`, `key_stage_coordinator` | NO — no door. |

### The teacher grant: what it EXPOSES, traced

`teacher` holds `activity_log.view` and `activity_log.view_own`
(`RbacSeeder.php:201-204`, spread at `:364`) and reaches `GET /api/activity-logs`. Then:

- **not school-wide, not module-scoped — OWN-ACTIONS-ONLY.** Without `view_all`,
  `ActivityLogQueryService.php:55-57` adds `causer_type = User::class AND causer_id = <this user>`.
  There is no module or `log_name` predicate anywhere in `baseQuery`; the causer predicate does all
  the work.
- **school-scoped as well.** Without `view_cross_school` (`:42`), rows are constrained to
  `ActiveSchool::id()`, and school-less system rows only with `view_system`, which teacher lacks.
- **sensitive rows excluded** (`:61`), teacher having no `view_sensitive`.
- **it cannot CAUSE a finance row.** The causer of `finance.invoice.returned` is the actor passed to
  `ReturnInvoice`, and that action asserts `finance.invoice.reject` against the actor — held by
  `internal_auditor` alone. `teacher` holds no `finance.*` ability at all.
- **and it cannot export.** `routes/endpoints/activity-log.php:25` puts
  `permission:activity_log.export` on the export route, INTERSECTING with the group gate.

**So the finding evaporates AS A TEACHER EXPOSURE.** A teacher's audit read is a read of their own
trail, and a teacher cannot produce a finance act, so the intersection is empty. **It does not
evaporate as a finding** — see correction (2): `admin`, `head_of_school` and `admin_viewer` hold
`view_all` and are not self-filtered.

**What is left of the teacher observation, in its smaller and still-true form:** a seat with no audit
remit is admitted through the audit API's front door, and the only thing standing between it and
other people's rows is a predicate inside a service rather than the gate. `routes/web.php:1064-1069`
records that this is exactly why the PAGE was gated on `view_all` — *"the GATE AND THE QUERY NOW KEY
ON THE SAME FACT"*. The API group has not had that treatment, and `routes/api.php:365-384` explains
why its gate is `view`: it was narrowed FROM `academic_data.view` to admit `internal_auditor`, which
had been locked out. Narrowing further to `view_all` would lock the auditor out again unless the two
were separated. A design note, not a defect, and not this commit's to make.

---

One premise I formed mid-pass and corrected before asserting it, recorded because it would have been
a confident wrong finding: the `finance_void_requests` maker≠checker rule is created as a **CHECK
constraint** (`2026_07_25_140000:99-100`), and production is MySQL 5.7, which parses and discards
CHECK silently. That would make it inert. It is **not** inert: `2026_08_17_100000` converted it to
`finance_void_requests_maker_ne_checker_bi` / `_bu` triggers (`:195` names it in the conversion list,
`:302-303` installs them), and `tests/Feature/Finance/CheckConstraintsAsTriggersTest.php:51` pins it.
Reading the create migration alone produces the wrong answer.

---

## A — The raise path: `GenerateInvoice`

**File** `app/Finance/Actions/GenerateInvoice.php`, 681 lines.

**Signature** — `:107`:

```php
public function handle(string $enrollmentUuid, array $lines, InvoiceKind $kind, ?int $actorId = null): Invoice
```

`$kind` is REQUIRED and never inferred (`:100-102` states the reason: inference would silently
misclassify a term bill as supplementary). `$actorId` is nullable and passed in from the controller
edge — the Action never calls `auth()` (boundary lint), `:103-105`.

**Preconditions, in execution order** (all `BusinessRuleException`):

| line | refusal |
| --- | --- |
| `:111-113` | enrollment uuid does not resolve through the `BillableEnrollmentProvider` port |
| `:171-173` | no active School context — Constitution rule 13, fail closed |
| `:175-179` | the episode's School could not be determined (`schoolId === 0`) |
| `:181-183` | the episode belongs to another School |
| `:185-187` | zero lines |
| `:212-214` | a line amount is zero |
| `:216-219` | a reduction line is not strictly negative |
| `:224-226` | a charge line is negative |
| `:244-248` | reductions exceed charges — the total would be negative |
| `:276` (via `:643-663`) | the episode already has an active TERM invoice — scheduled kinds only |
| `:353-363` | the same invariant surfacing as MySQL 1062 from the unique index |
| `:372-374` | the `reduction_guard` trigger's own message, translated to a 422 |

Three resolutions happen server-side and overwrite whatever the caller supplied — discountability
from the fee item (`:391-410`), the percentage base from the cited policy (`:412-`), and percentage
lines resolved into concrete signed amounts (`:204`).

**What it writes**, all inside one `DB::transaction` opened at `:251`:

- the `finance_invoices` row — `:281-291`. `status` is `InvoiceStatus::Issued`, hardcoded at `:286`;
  the total is DERIVED at `:234-239` by exact integer `Money::plus` and can never be asserted by a
  caller.
- one `finance_invoice_lines` row per line — `:293-313`, each carrying `created_by_user_id = $actorId`
  (`:311`).
- the ledger charge — `:315-328`.
- carry-forward credit allocation rows, when pre-charge credit exists — `:336-342`. Settlement links
  only; no ledger row (`:331-333`).

**What it locks** — exactly one thing: the `StudentAccount` row, `lockForUpdate()` at `:261-264`, and
it is the FIRST statement in the transaction. `:252-256` states why: a locking read does not
establish the REPEATABLE READ snapshot, so the credit read at `:270` is a current read. **It does not
lock the invoice, the episode, or anything else.** The one-active-scheduled-invoice invariant is held
by the DB unique index, not by a lock (`:44-52`).

**Can the caller influence the ledger charge's effective date?** **No.** `SchoolDay::today()` is a
hardcoded argument at `:327`. It is not a parameter, has no default to override, and reaches
`SubledgerPoster::post()`'s required 8th argument (`app/Finance/Services/SubledgerPoster.php:100`).
The comment at `:323-326` states the intent explicitly: *"They diverge for corrections and for
migrated history, not for an invoice raised in the ordinary course."* A correction is the case that
comment names as the exception, and no mechanism for it exists here.

---

## B — The slot

**The mechanism.** A STORED GENERATED column plus a UNIQUE index on `finance_invoices`:

```
active_enrollment_key = IF(status = 'issued' AND kind = 'scheduled', student_curriculum_id, NULL)
UNIQUE finance_invoices_active_enrollment_unique (school_id, active_enrollment_key)
```

Installed by `2026_07_19_120000_slice2_invoice_total_immutable_and_active_enrollment_guard.php:76-81`
(then as `IF(status='issued', …)`), re-keyed to add the `kind = 'scheduled'` conjunct by
`2026_08_18_100000_finance_invoices_kind_and_scheduled_only_episode_guard.php:57`. Read-only in PHP —
`app/Finance/Models/Invoice.php:35-40` says never write it.

Any status other than `issued` recomputes the key to NULL, and NULLs do not collide in a MySQL unique
index. So a void frees the slot **by recomputation, at the moment the `status` column is written** —
there is no separate release step. `ApproveVoidRequest.php:73` names this: *"Flip the invoice → void
(releases the F7 slot now)"*.

**A void and a re-raise inside ONE transaction.** Measured facts:

- Nested `DB::transaction` calls become SAVEPOINTs, not new transactions —
  `tests/Feature/Finance/InvoiceConcurrencyTest.php:213` states it for exactly this pair:
  *"ApproveVoidRequest: its own DB::transaction nests as a savepoint, so it inherits THIS"*.
- Neither `GenerateInvoice` nor `ApproveVoidRequest` guards against being called inside an open
  transaction — no `DB::transactionLevel()` anywhere in either file (grep returned zero; the same
  grep over `app/` finds the token nowhere in those two files).
- **Nothing in the suite exercises void-then-re-raise inside one transaction.** EXAMINED: 11 test
  files name `ApproveVoidRequest`; of those exactly 1 also opens a `DB::transaction`
  (`InvoiceConcurrencyTest.php`), and its two arms are *racing generates* (`:128`) and *racing void
  approvals* (`:192`). Neither is the pair. Positive control: the same loop with the condition
  relaxed to "has `DB::transaction`" printed that one file, so the loop can print.

**Is there a window where the slot is free, and can anything else take it?** — **INFERRED, not
measured, and flagged as the one place in this document resting on InnoDB semantics rather than on a
run.** Within the transaction the slot is free to *that* transaction from the `UPDATE` onward, which
is why a same-transaction re-raise would insert. To another session the old index entry is
delete-marked and lock-held until commit, so a concurrent insert of the same key blocks rather than
succeeds. **I did not construct this.** It is exactly the class of claim the repo's own history says
to distrust, and the honest status is: no test covers it, so treat it as UNVERIFIED.

**VOID PROOF 7** — `tests/Feature/Finance/FinanceApiAcceptanceTest.php:984-1003`.

What it does: raises a bill, submits a void, approves it over HTTP (`:992`), then raises a fresh bill
for the SAME episode (`:994-997`) and asserts two things (`:1001-1002`) — two invoice rows for the
episode, exactly one with a non-NULL `active_enrollment_key`.

What it **does not** assert, each relevant to a correction mechanism:

- **nothing links the two invoices.** It counts them; it does not relate them. This is §E's finding
  restated from the test side.
- **the two acts are separate HTTP requests, so separate transactions.** It is not evidence about the
  single-transaction case above.
- **nothing about the reversal's `effective_at`** — the period question is untouched.
- **nothing about the replacement's release state.** The re-raise creates an invoice with
  `reviewed_at` NULL, but no arm reads it, so the arm would pass if the replacement arrived released.
- **the same actor does both** (`$maker` at `:989` and `:994`). No duty separation is exercised.
- **the "only after approval" half is borrowed**, not proven here — `:991` defers to PROOF 1.

---

## C — The void path, and what a correction would skip

### Everything structurally required today

**Maker side — `app/Finance/Actions/SubmitVoidRequest.php`** (96 lines):

| line | requirement |
| --- | --- |
| `:48` | `SchoolContext::assertOwns($invoice, 'invoice', 'voided')` — rule 13 |
| `:50-52` | a non-blank reason |
| `:54-56` | the invoice is not already void |
| `:58-61` | `VoidEligibility::blocker()` — hard refusal, advisory in intent (`:22-29`) |
| `:63-69` | no open request already exists (friendly pre-check) |
| `:73-75` | `ApprovalRequirement::for(...)->required` — else `LogicException` |
| `:77-83` | the `finance_void_requests` INSERT |
| `:87-92` | `notifyApprovalCheckers`, after commit |

**Checker side — `app/Finance/Actions/ApproveVoidRequest.php`** (129 lines):

| line | requirement |
| --- | --- |
| `:45` | `SchoolContext::assertOwns($request, 'void request', 'approved')` |
| `:47-49` | the request is pending |
| `:51-53` | friendly maker ≠ checker |
| `:57` | `lockForUpdate()` on the INVOICE row, first statement in the transaction |
| `:60-62` | already-void re-check **under the lock** |
| `:65-68` | `VoidEligibility::blocker()` re-check — authoritative (`:29`) |
| `:71` | `transitionTo(Approved, $checker->id)` — writes `decided_by` / `decided_at` |
| `:75-80` | the invoice flip: `status`, `cancelled_at`, `cancelled_by_user_id`, `cancel_reason` |
| `:82-103` | the reversing ledger post |

**At the database — `finance_void_requests`:**

| object | migration | what it refuses |
| --- | --- | --- |
| `open_key` STORED generated + `UNIQUE (school_id, open_key)` | `2026_07_25_140000:86-91` | a second OPEN request per invoice, under concurrency |
| `finance_void_requests_maker_ne_checker_bi` / `_bu` | `2026_08_17_100000:195,:302-303` (replacing the CHECK at `2026_07_25_140000:99-100`) | `submitted_by = decided_by` on INSERT and UPDATE |
| `finance_void_requests_no_delete` | `2026_07_25_140000:105-106` | every DELETE |
| the update guard | `2026_07_25_140000:114-123` | any change beyond `status` / `decided_by` / `decided_at` / `rejection_reason` |

**Permissions, and who holds them.** RE-DERIVED by EXECUTING `Database\Seeders\RbacSeeder::grantsMap()`
against the composer autoloader — not grepped. 15 roles in the map. Absent control
(`finance.invoice.zzz-no-such-ability`) returned 0 holders; every ability below returned ≥1, so the
matcher is neither broken-open nor broken-closed.

```
ROLE                  view          view_all      access        generate      approve       reject        submit        approve       
admin                 YES           YES           YES           YES           .             .             .             .             
head_of_school        YES           YES           .             .             .             .             .             .             
teacher               YES           .             .             .             .             .             .             .             
registrar             .             .             .             .             .             .             .             .             
guardian              .             .             .             .             .             .             .             .             
principal             .             .             YES           .             .             .             .             .             
boarding_parent       .             .             .             .             .             .             .             .             
key_stage_coordinator .             .             .             .             .             .             .             .             
form_teacher          .             .             .             .             .             .             .             .             
accounts_officer      .             .             YES           YES           .             .             YES           .             
executive_director    .             .             YES           .             .             .             .             YES           
accounts_supervisor   .             .             YES           .             .             .             .             .             
finance_lead          .             .             YES           .             .             .             .             .             
internal_auditor      YES           YES           .             .             YES           YES           .             .             
super_admin           .             .             .             .             .             .             .             .             

ROLES: 15
control activity_log.view                             holders=4
control activity_log.view_all                         holders=3
control finance.access                                holders=6
control finance.invoice.generate                      holders=2
control finance.invoice.approve                       holders=1
control finance.invoice.reject                        holders=1
control finance.invoice.void-request.submit           holders=1
control finance.invoice.void-request.approve          holders=1
```

So: **void submit is `accounts_officer` alone; void approve and void reject are `executive_director`
alone.** `VoidRequestPolicy:25-32` additionally requires `isNotTheMaker` on both.

### If a correction sets `status = Void` without a `VoidRequest` row

Enumerated, one line each. **No judgement is offered on whether any of these should be skipped.**

**Skipped from the maker side (`SubmitVoidRequest` never runs):**

1. `SchoolContext::assertOwns` on the invoice — `:48`.
2. The required non-blank reason — `:50-52`.
3. The already-void check — `:54-56`.
4. `VoidEligibility::blocker()` at submit: no allocated payment, no approved credit note — `:58-61`.
5. The one-open-request pre-check — `:63-69`.
6. `ApprovalRequirement::for()` — the ADR 0051 seam is never consulted, so the correction is outside
   the mechanism that will later carry per-school approval rules — `:73-75`.
7. `notifyApprovalCheckers` — no checker is told anything happened — `:87-92`.

**Skipped at the database, because no row is inserted:**

8. `UNIQUE (school_id, open_key)` — the concurrent double-submit guard.
9. `finance_void_requests_maker_ne_checker_bi` / `_bu` — maker ≠ checker at the DB, the layer that
   survives a bypassed Action.
10. `finance_void_requests_no_delete` — the append-only-ness of the decision record.
11. The update guard restricting which of the request's columns may ever change.

**Skipped from the checker side (`ApproveVoidRequest` never runs):**

12. `SchoolContext::assertOwns` on the request — `:45`.
13. The pending-state check — `:47-49`.
14. The friendly maker ≠ checker guard — `:51-53`.
15. `lockForUpdate()` on the invoice row — `:57`. This is the serialisation of concurrent decisions;
    without it two correctors can both read `issued`.
16. The already-void re-check under that lock — `:60-62`. **This is the only duplicate-reversal
    guard that exists**: `:26-27` states there is no ledger-level source-uniqueness, so the one-way
    invoice transition IS the guard.
17. The AUTHORITATIVE `VoidEligibility::blocker()` re-check under the lock — `:65-68`. This is what
    catches a payment landing between submit and approve.
18. `transitionTo(Approved, $checker)` — no `decided_by`, no `decided_at`, no decision record at all.
19. The reversing ledger post — `:82-103`. **A `status = 'void'` written without this leaves the
    original charge standing in the ledger against a bill that reads void**, so the student's balance
    keeps the charge.
20. `originalChargeEffectiveAt()` — `:117-128`. The period decision (§F) is skipped with it.
21. `cancelled_at`, `cancelled_by_user_id`, `cancel_reason` — `:75-80`. A correction writing only
    `status` leaves all three NULL, and `cancel_reason` is the only column on the invoice that says
    why it was voided.

**Skipped in authorisation:**

22. `VoidRequestPolicy::approve` — `finance.invoice.void-request.approve` **plus** `isNotTheMaker`.
    Held by `executive_director` alone. This is exactly where Brookstone's requirement 1 lands.

**NOT skipped — the one guard that fires:**

23. `tests/Arch/InvoiceVoidHasOneWriterTest.php` reds until the new writer is added to the permitted
    list. Demonstrated in §H.

---

## D — The approval seam

**Path** `app/Finance/Approval/ApprovalRequirement.php` — 52 lines. **The brief's path is correct.**

**What `for()` returns today** — `:48-51`, the entire body:

```php
public static function for(string $makerAbility, ?Money $amount = null): self
{
    return new self(required: true);
}
```

Unconditionally `required: true`, `ruleId` defaulting to null (`:35`).

**Why it is what it is.** `:43-46` states the rule: *"FAIL CLOSED, and this is the guarantee: until
`finance_approval_rules` exists, EVERY finance maker action requires a checker — including an ability
string that matches no pair at all. When the table lands, this body becomes a lookup on (school_id,
maker ability) where an ABSENT row still means 'approval required' — never the reverse."*

The return type is a value object rather than a bool, and `:20-29` names that as the one deliberate
shaping-ahead: a straight-through row must record WHICH RULE authorised it, because every approval
table carries `CHECK (submitted_by IS NULL OR decided_by IS NULL OR submitted_by <> decided_by)` — so
"approval not required" can never be "the maker auto-approves their own row". A straight-through row
is `decided_by IS NULL` with the approval attributed to a rule, not a person.

**The `LogicException`, quoted** — `SubmitVoidRequest.php:73-75`:

```php
if (! ApprovalRequirement::for(Permission::FINANCE_INVOICE_VOID_REQUEST_SUBMIT->value, $invoice->total)->required) {
    throw new \LogicException('Straight-through submission is not implemented — see ADR 0051.');
}
```

ADR 0051 (`docs/adr/0051-approval-requirement-is-configuration.md`, Accepted 2026-07) states at `:34-36`
that the throwing arm is *"not a live path: a half-built straight-through arm that silently created an
unapproved row would be far worse"*.

**What this means for Brookstone's requirement 1.** The seam was built to allow exactly the shape
requirement 1 asks for — a transaction that does not need a second signature — and it is the one named
place where that decision is meant to live. It is **inert today**: nothing can make `for()` return
`required: false`, and the four call sites would throw if it did. Two constraints ADR 0051 places on
whatever fills it:

- `:82-86` — the never-configurable invariant: *"No configuration this seam ever grows may let a single
  role hold both sides of a pair… A straight-through rule removes the second signature; it never merges
  the two signatures into one person."*
- `:90-95` — `bin/ci-boundary-lint.php` enforces `approval-seam-missing` (every
  `app/Finance/Actions/Submit*.php` must consult the seam) and `approval-seam-count` (the count of
  `Submit*.php` actions must equal the count of finance `*_SUBMIT` permissions). **A correction action
  named `Submit*` inherits both lints; one not so named inherits neither.**

---

## E — The audit record, against the four requirements

### What exists

**`finance_invoices.return_reason`** and its two companions — `2026_09_04_100000:175,:178,:182`:

```php
$table->timestamp('returned_at')->nullable()->after('reviewed_by_user_id');
$table->unsignedBigInteger('returned_by_user_id')->nullable()->after('returned_at');
$table->string('return_reason')->nullable()->after('returned_by_user_id');
```

`returned_by_user_id` is a LOOKUP, not an FK (`:125-129`). `return_reason` is VARCHAR(255), the length
read from `cancel_reason` and the sibling `rejection_reason` columns rather than chosen (`:131-133`).

A pairing trigger on BOTH `BEFORE INSERT` and `BEFORE UPDATE` (`:197-204`) SIGNALs 45000 unless
`returned_at` arrives with both companions. **The inverse arm is deliberately unenforced** (`:82-87`) —
`return_reason` set while `returned_at` is NULL is not refused. And empty-string reasons are not
refused at the DB (`:89-94`); `ReturnInvoice.php:154-165` refuses them with `mb_strlen`, refusing rather
than truncating.

**A gap the migration states about itself** — `:60-69`: `reviewed_at` and `returned_at` both set is a
state **nothing at the database refuses**. It names the two places it could be closed when the ruling
lands: a third trigger arm, or `whereNull('returned_at')` in `ApproveInvoice`'s compare-and-swap. That
second one has since been done — `ApproveInvoice.php:174`. The trigger arm has not.

**The activity-log entries.** EXAMINED: 15 `@activity-emits` declarations across `app/`; two concern
invoices.

`ReturnInvoice.php:214-227` writes `finance` / `invoice.returned`, `performedOn` the invoice,
`causedBy` the actor, with properties `invoice_uuid`, `student_id`, `returned_at`, `return_reason`.
`:97-103` makes the reason's inclusion a ruling rather than a default: *"because Phase B lets Finance
resubmit a corrected bill — after which a second return OVERWRITES all three columns — this row is the
only place the FIRST return's instruction will exist. The columns are current state; the log is
history, and here it is the only history."*

`ApproveInvoice.php:197-208` writes `finance` / `invoice.approved` with `invoice_uuid`, `student_id`,
`released_at`. **No reason** — a release has none.

### Does anything link a voided bill to the bill that replaced it?

**No. Nothing does.** Stated as an absence, with the search and its controls.

**Instrument 1 — the model's property block.** `app/Finance/Models/Invoice.php:42-62` enumerates every
column on `finance_invoices`: `id`, `uuid`, `school_id`, `student_id`, `student_curriculum_id`,
`number`, `status`, `kind`, `billed_to_name`, `academic_context`, `total`, `active_enrollment_key`,
`cancelled_at`, `cancelled_by_user_id`, `cancel_reason`, `reviewed_at`, `reviewed_by_user_id`,
`returned_at`, `returned_by_user_id`, `return_reason`, `created_at`. **21 columns, no invoice-to-invoice
reference among them.**

**Instrument 2 — the migrations.** EXAMINED: 181 migration files, 26 of which name `finance_invoices`.
Every column-adding statement in those was enumerated; the only additions to this table after creation
are `reviewed_at` / `reviewed_by_user_id` (`2026_08_31_100000:120,:123`) and the three return columns
(`2026_09_04_100000:175,:178,:182`). No linkage column is added by any of them.

**Instrument 3 — a name search across `app/`, `database/` and `tests/`** (634 + 181 + 305 files) for
`replaced_by|replaces_|supersed|original_invoice|parent_invoice|previous_invoice|predecessor|reissue|
re_issue|corrects_|correction_of|replacement_invoice|source_invoice_id|prior_invoice`, case-insensitive
and with no `\b` (BSD grep has none). **161 hits, and not one of them is an invoice-to-invoice link.**
They are: `supersedes_policy_id` on `finance_discount_policies`, `supersedes_schedule_id` on
`finance_fee_schedules`, the `Superseded` status enum cases for those two tables, `reissue` on
admission/staff numbers and guardian credentials, and prose.

- **Positive control:** the same grep machinery, pointed at `returned_by_user_id|cancelled_by_user_id`
  over `database/migrations`, returned **18** hits. The grep is not broken-closed.
- **Absent control:** `zzz_no_such_column_zzz` returned **0**. It is not broken-open.

**That the search finds `supersedes_policy_id` and `supersedes_schedule_id` is the useful half of the
absence.** This codebase already has an idiom for "row B replaces row A": a nullable
`supersedes_<thing>_id` LOOKUP column plus a state-scoped unique index, written by the approval action
in one transaction (`ApproveDiscountPolicyChange.php:119`, `ApproveFeeScheduleChange.php:93`;
`2026_07_26_130000:42`, `2026_07_26_140000:47`). Invoices have never acquired it. So the absence is a
gap in a pattern the repository otherwise follows, not a road it has never travelled.

**The ledger does not close it either.** `SubledgerPoster::post()` keys rows on
`(source_type, source_id)`; the reversal carries `('invoice', <old id>)`
(`ApproveVoidRequest.php:87-88`) and the replacement's charge carries `('invoice', <new id>)`
(`GenerateInvoice.php:320-321`). They share a student and a school and nothing else.

**Nor does the activity log.** Neither emitter carries a second invoice id; `invoice.returned` and
`invoice.approved` each name one invoice. And no `@activity-emits` exists for a void at all — the void
path writes no activity row.

### Against the four requirements

| requirement | status today |
| --- | --- |
| 1 — no ED approval | The only void path routes through `finance.invoice.void-request.approve`, held by `executive_director` alone. The seam that could relax it (§D) is inert. **Not satisfiable today.** |
| 2 — corrected bill returns to IA | The release axis exists and defaults correctly: a newly raised invoice has `reviewed_at` NULL (`GenerateInvoice` never writes it), so a replacement is unreleased by construction. `ApproveInvoice` is the sign-off writer. **The mechanism exists; nothing routes a *replacement* into it as a distinct thing.** |
| 3 — full record | Partial. The **reason for return** exists (column + activity row). **Who and when** exist for return and release. **What the bill said before and says now** does NOT exist as a link — see the absence above; two unlinked invoice rows and their lines are all there is. |
| 4 — Finance and IA only, never the parent | See §I for the parent half (satisfied). The **Finance** half is not: measured above, no Finance role holds any activity-log ability. **[CORRECTED 2026-09-06 — partially satisfied, not unsatisfied; see the correction section above.]** |

**The requirement-4 finding, stated plainly.** The activity-log API is gated on `activity_log.view`
(`routes/api.php:375`) and the page on `activity_log.view_all` (`routes/web.php:1062`). Executing
`grantsMap()`: `activity_log.view` is held by `admin`, `head_of_school`, `teacher`, `internal_auditor`;
`activity_log.view_all` by `admin`, `head_of_school`, `internal_auditor`. **No Finance role holds
either** — not `accounts_officer`, not `finance_lead`, not `accounts_supervisor`, not
`executive_director`, not `principal`. And `teacher` holds `activity_log.view`. So if the correction
history lives in the activity log and nothing else, requirement 4 is missed in **both** directions:
Finance cannot see it, and a role that is neither Finance nor Internal Audit can. `guardian` holds
neither, so the "never the parent" half holds.

> **[CORRECTED 2026-09-06.]** Three things. (i) The holder LISTS were right at this document's own
> merge commit and are now short a role: `origin/staging` has since added `admin_viewer`, holding
> `view`, `view_all`, `view_own` and `view_sensitive`. (ii) *"Finance cannot see it"* is too wide —
> `accounts_officer` sees the CURRENT return (reason, returner, timestamp) on
> `GET /finance/returned-bills`; the other three Finance seats do not. (iii) The `teacher` example is
> wrong: its `activity_log.view` is self-filtered to its own acts, which cannot include a finance
> act. The CLAIM that example supported is right, through `admin`, `head_of_school` and
> `admin_viewer`. See the correction section near the top.

---

## F — The period seam

`SubledgerPoster::post()` takes `effective_at` as a **required 8th argument** with no default —
`app/Finance/Services/SubledgerPoster.php:92-101`. Every caller must therefore decide it, and the
decision is visible at every call site.

**EXAMINED:** all 634 PHP files under `app/`. `grep -rn '\->post('` returns 10 hits; 3 are HTTP clients
(`PaystackClient.php:115,:251`, `HttpCallbackTransport.php:56`). **7 real ledger-post sites**, and the
8th argument at each:

| site | entry type | `effective_at` |
| --- | --- | --- |
| `GenerateInvoice.php:315` | `Charge` | `SchoolDay::today()` — `:327` |
| `PostOpeningBalanceBatch.php:214` | `Charge` | `$locked->cutover_date->toDateString()` — `:226` |
| `ApproveVoidRequest.php:82` | `Reversal` | `$this->originalChargeEffectiveAt($invoice)` — `:102` |
| `ApproveCreditNote.php:93` | `CreditNote` | `SchoolDay::today()` — `:118` |
| `RecordPayment.php:161` | `Payment` | `$receivedAt` — `:176` |
| `RecordAccountPayment.php:107` | `Payment` | `$receivedAt` — `:119` |
| `PostOpeningBalanceBatch.php:302` | `Payment` | `$locked->cutover_date->toDateString()` — `:313` |

Absent control (`->postZZZ(`) returned 0; positive control confirmed the pattern finds the two known
sites.

**(i) A void's reversal — exactly ONE site.** `ApproveVoidRequest.php:102`, delegating to the private
`originalChargeEffectiveAt()` at `:117-128`, which reads the ORIGINAL CHARGE's own `effective_at` from
`finance_ledger_transactions` and falls back to the invoice's `created_at` (a fallback `:112-115`
states is unreachable today: the column is NOT NULL and the table was empty when it was added).

**The brief's belief is confirmed.** The reasoning is spelled out at `:90-101` and it is a *deliberate
accounting decision flagged for the project lead to overturn*: a void says the invoice should never
have existed, `VoidEligibility` guarantees nothing settled against it, so the honest record is one
period in which the charge and its reversal both appear and net to zero. Dating the reversal today
would leave the original period overstated forever and understate the current one by the same amount.

**(ii) A newly raised charge — TWO sites, not one.** `GenerateInvoice.php:327` (`SchoolDay::today()`,
the ordinary course) and `PostOpeningBalanceBatch.php:226` (the cutover date, for migrated history).
They do not share a helper; the two are independent literals in two files.

**So the seam Brookstone's answer plugs into is not one place — it is one place for the reversal and
two for the charge.** A correction posts both a reversal and a replacement charge, so a period rule
would land on `ApproveVoidRequest::originalChargeEffectiveAt()` **and** on whatever the correction's
raise path is. Only the first is a named method today; `GenerateInvoice`'s is a hardcoded argument.

**Also relevant, and not a period decision but adjacent:** `ApproveCreditNote.php:118` dates a credit
note `SchoolDay::today()` — a reversal-shaped posting that does NOT follow the void's rule. The two
negative postings in this system already disagree about periods, deliberately.

---

## G — The permission

**Does an ability for this act exist?** **No.** EXAMINED: `app/Enums/Permission.php`. The invoice
abilities that exist are `finance.invoice.generate` (`:178`), `finance.invoice.approve` (`:188`),
`finance.invoice.reject` (`:203`), and the three void-request abilities (`:172-174`). Nothing named for
correction, resubmission, amendment or re-issue. Executing `grantsMap()` for a fabricated
`finance.invoice.zzz-no-such-ability` returned 0 holders, confirming the lookup distinguishes absence
from presence.

**No permission is proposed here.** What follows is the set of constraints one would have to satisfy,
derived from the mechanisms that would act on it.

**The naming convention the module implies.** The Ph2 scheme is `finance.<resource>.<verb>`
(`Permission.php:131`), and the void pair extends it to a four-segment
`finance.invoice.void-request.<verb>` when the resource is the *request* rather than the invoice
(`:172-174`). So the shape is settled by which noun the act operates on; the terminal segment is the
part that carries mechanical consequences.

**What `ApprovalAbility` would then require** — `app/Support/ApprovalAbility.php`:

- `CHECKER_SEGMENTS = ['approve', 'reject']` (`:40`). Any ability whose TERMINAL segment is one of
  these is (a) excluded from the `super_admin` `Gate::before` bypass —
  `isExcludedFromSuperAdminBypass()`, `:86-89` — and (b) treated as a checker action by the C6 grant-time
  duty-separation guard. `SuperAdminBypassExclusionTest` enumerates `App\Enums\Permission` and asserts
  every terminally-approve/reject case is excluded, so a new one is covered the day it is created,
  without anyone remembering (`:22-27`).
- `matchingMakerFor()` (`:113-131`) derives the maker by replacing the terminal segment with `submit`.
  **A checker ability whose maker is not named `…submit` must be declared in `MAKER_OVERRIDES`**
  (`:78-81`), which today holds exactly two entries, both pointing at `finance.invoice.generate`.
- **`MAKER_OVERRIDES` is asserted, and the assertion is sharp.** `:64-74` records that the number that
  matters is the number of DISTINCT makers, that it is 1, and that `SuperAdminMatrixTest` asserts both
  the exact entry count and `array_unique` over the values being 1. `GrantsMapSeparationTest`
  separately asserts the map names only real permissions on both sides and reds on an unrecognised
  constant. **A new override pointing at a SECOND pre-convention maker reds both**, and `:72-74` states
  the intended reading of that red: the convention is failing, and the fix is to name the next maker
  for the convention rather than to grow the list.

**Duty separation against `finance.invoice.generate`.** The C6 grant-time guard forbids any role
ending up holding a checker together with its matching maker. Today `finance.invoice.generate` is held
by `admin` and `accounts_officer` (executed, 2 holders). Consequences a new ability would inherit:

- If the correction ability terminates in `approve`/`reject` and derives or declares
  `finance.invoice.generate` as its maker, **`accounts_officer` cannot hold it** — that role holds
  `generate`. `accounts_officer` is also the sole holder of `finance.invoice.void-request.submit`.
- If it terminates in `submit`, it is a MAKER and is subject to the guard from the other side: no role
  may hold it together with its derived checker.
- If it terminates in neither, it is invisible to all of the above — no bypass exclusion, no duty
  separation pairing, no `MAKER_OVERRIDES` obligation. **That is a choice with consequences, not a
  neutral default**, and it is the shape `finance.invoice.generate` itself has.
- ADR 0051 `:82-86` binds independently of naming: no configuration may let a single role hold both
  sides of a pair.

**One measured oddity, recorded rather than resolved.** `internal_auditor` holds
`finance.invoice.approve` and `finance.invoice.reject` but **not** `finance.access`. Whatever gates the
Finance surfaces, the auditor reaches the review verbs without it. I did not chase how; it is stated
because a correction ability granted to a Finance role and a review ability granted to IA sit on
opposite sides of that line.

---

## H — The arch gate

**File** `tests/Arch/InvoiceVoidHasOneWriterTest.php`, 1206 lines, group `arch`.

**The exact edit a second writer requires.** Two functions, both of which must be changed:

`voidWriterPermittedFiles()` — `:185-192`:

```php
    return [
        // The maker-checker void approval. …
        'app/Finance/Actions/ApproveVoidRequest.php',
    ];
```

`voidWriterPermittedClasses()` — `:199-204`, which pins each path to the FQCN it must declare, so a
move or rename reds rather than silently re-pointing the pin (`:176-178`):

```php
    return [
        'app/Finance/Actions/ApproveVoidRequest.php' => 'App\Finance\Actions\ApproveVoidRequest',
    ];
```

The gate anticipates this exactly — `:180-181`: *"ADDING A SECOND ENTRY IS THE POINT OF THIS LIST, not
a workaround for it: the correction mechanism now being designed adds a legitimate one. Whoever adds
it writes the reason beside it."*

### Demonstrated, not asserted

Run through `bin/db-exclusive` (no ad-hoc `ps | grep` preflight was written). Exit codes captured
directly, never through a pipe.

**1 — Baseline, clean tree:**

```
{"tool":"pest","result":"passed","tests":15,"passed":15,"assertions":67,"duration_ms":401}
```

**2 — Plant.** `app/Finance/Actions/CorrectReturnedInvoice.php` created, containing
`$invoice->update(['status' => InvoiceStatus::Void]);` and nothing else of substance. Gate re-run:

```
EXIT=1
{"tool":"pest","result":"failed","tests":15,"passed":14,"assertions":65,"failed":1,
 "failures":[{"test":"…it_produces_InvoiceStatus__Void_in_exactly_the_classes_on_the_permitted_list",
 "line":785,"message":"Failed asserting that two arrays are identical.
--- Expected
+++ Actual
 Array &0 [
     0 => 'app/Finance/Actions/ApproveVoidRequest.php',
+    1 => 'app/Finance/Actions/CorrectReturnedInvoice.php',
 ]"}]}
```

**Blast radius, recorded rather than just the flip: 1 of 15 arms red, 14 green.** The red arm is the
permitted-list identity arm at `:785`, and it names the planted file exactly. The other fourteen were
right to stay green — they assert the scanner's own coverage (examined / excluded / unrecognised /
unlistedCall / dynamicCase / unbalanced, each asserted zero), the raw-SQL site list, and the mutator /
reader method lists, none of which the plant touches. Assertion count fell 67 → 65: the two
permitted-file assertions for the planted entry did not run.

**3 — The exact edit, applied.** Both functions given the second entry. Gate re-run:

```
EXIT=0
{"tool":"pest","result":"passed","tests":15,"passed":15,"assertions":69,"duration_ms":372}
```

Green, and assertions rise 67 → **69** — the two extra being the new path's existence-and-FQCN pair.
That the number moves is itself the evidence the new entry is being checked rather than merely
tolerated.

**4 — Restored.** Plant deleted, arch test restored from backup. `git --no-optional-locks status
--porcelain` returned **empty**. Gate re-run on the clean tree:

```
EXIT=0
{"tool":"pest","result":"passed","tests":15,"passed":15,"assertions":67,"duration_ms":374}
```

Back to the baseline numbers exactly. **The gate reds on an unlisted second writer and greens on the
listed one — measured in both directions, with the known-negative arm the repo's method requires.**

---

## I — Visibility

**Requirement 4's parent half: can a voided bill, or a returned generation of one, reach a payer-facing
surface today? — No.**

### The scope

`app/Finance/Models/Invoice.php:301-304`:

```php
public function scopeWithheldFromPayers(Builder $query): Builder
{
    return $query->releasedToPayers(false);
}
```

It is a call INTO `scopeReleasedToPayers()` (`:285-290`), not a second predicate, so the negation
cannot drift from the assertion (`:263-266`). The underlying predicate is
`whereNotNull|whereNull(self::RELEASE_STAMP_COLUMN)`, and `RELEASE_STAMP_COLUMN` is `'reviewed_at'`
(`:237`). **A named scope and never a global one** — `:273-278` gives the reason: a global scope would
hide unreleased bills from the bursar, the statement, the duplicate guard's own read and the auditor
who is supposed to review them.

`:280-283` claims exactly two consumers, both in `InvoiceReadModel` and both parent-facing. **Verified
rather than accepted:** EXAMINED `app/` and `tests/` — `ithheldFromPayers` returns 3 hits, of which
one is the definition (`Invoice.php:301`), one is prose (`Invoice.php:265`), and one is the single call
site (`InvoiceReadModel.php:473`). Positive control: `scopeVisibleToPayers|RELEASE_STAMP_COLUMN`
returned 20 hits; absent control returned 0.

### Every parent-facing read, and its denominator

EXAMINED: `routes/api.php` holds 171 `Route::` declarations. Parent-facing finance is declared in one
included file — `routes/endpoints/parent-finance.php`, which contains **3** routes total. Plus one
guardian ward list on the main file, and one Inertia page.

| # | surface | reaches invoices via | void? | withheld / returned? |
| --- | --- | --- | --- | --- |
| 1 | `GET /parent/finance/wards` — `parent-finance.php:35`, `GuardianFinanceController::wards` (`:65-80`) | `InvoiceReadModel::outstandingForStudent()` | **excluded** — `->excludingVoid()`, `InvoiceReadModel.php:98` | **excluded** — `->releasedToPayers()`, `:99` |
| 2 | `POST /parent/invoices/{invoice}/payment` — `parent-finance.php:68`, `GatewayPaymentController::store` (`:25`) | `InitiateGatewayPayment` | **refused** — `isVoid()`, `InitiateGatewayPayment.php:138-140` | **refused** — `! isReleasedToPayers()`, `:132-136` |
| 3 | `POST /parent/invoices/{invoice}/payment/preview` — `parent-finance.php:84`, `::preview` (`:79-103`) | `PreviewGatewayFee::handle` → `payableGross` | **refused** — same guards | **refused** — same guards |
| 4 | `GET /parent/wards` — `routes/api.php:469`, `GuardianController::wards` | no invoice read | n/a | n/a |
| 5 | `GET parent/finance` page — `routes/web.php:1216-1217` | renders; data from #1 | n/a | n/a |
| 6 | the activity log — `routes/api.php:375` (`activity_log.view`), `routes/web.php:1062` (`activity_log.view_all`) | — | `guardian` holds **neither** (executed matrix, §C) | same |

**Route 3's guard was verified, not accepted.** `GatewayPaymentController.php:89-90` claims *"Same
refusals as `store`, in the same order, because they are literally the same guards."* That is a
description asserting a property, so it was checked: `PreviewGatewayFee::handle` calls
`InitiateGatewayPayment::payableGross()` (`PreviewGatewayFee.php:70`), whose first statement is
`$this->assertPayable($invoice, $bill)` (`InitiateGatewayPayment.php:165-170`) — the same method
`store` uses. The claim holds.

**A returned bill is excluded by construction, not by a second predicate.** `ReturnInvoice` never
touches `reviewed_at` (`2026_09_04_100000:35-41`: *"Returned is a SECOND axis, not a move along the
first"*), and it refuses a bill that is already released (`ReturnInvoice.php:181`, `:243-261`) with
that refusal riding in the compare-and-swap (`:190`) rather than being advisory. So `returned_at IS NOT
NULL` implies `reviewed_at IS NULL`, and `releasedToPayers()` excludes it. **This is derived, and it
rests on the actions — the migration states at `:60-69` that the database refuses nothing here.**

### The one thing that is NOT hidden, and it is correct

`InvoiceReadModel.php:448-450`: a VOID invoice is **not** in the withheld set —
`guardianAccountPositionForStudent()` applies `excludingVoid()` before `withheldFromPayers()` (`:472-473`),
so a void bill's charge and its reversal both stay in the balance where they net to zero. The
comment names this as arrived at *by not making an exception rather than by making one*. Net effect on
the payer's balance: nil. The bill itself never appears.

### `mayPay()` — recorded because it looks like a hole and is not one

`app/Finance/Services/GuardianPaymentAuthorisation.php:49-52` is the `authorize()` for routes 2 and 3
(`InitiateGatewayPaymentRequest.php:38-42`), and its entire body is
`$this->guardians->isWardOf($user, (int) $invoice->student_id)`. **It checks ward-ownership and nothing
else** — not void, not release, not return. Grepping that file for
`isVoid|releasedToPayers|reviewed_at|returned_at` returns **0**.

That is not a leak, because `assertPayable` refuses downstream before the provider is called
(`InitiateGatewayPayment.php:66`: *"every refusal, all of them before the provider is called"*). It is
recorded because **the authorisation layer carries none of this and the action carries all of it** — a
fourth parent-facing route added tomorrow that resolves an invoice and does not route through
`assertPayable` inherits no protection from `mayPay()`.

**One residual, unmeasured and stated as such.** `InitiateGatewayPayment.php:132` checks
`! isReleasedToPayers()` BEFORE `:138` checks `isVoid()`, so a bill voided while unreleased is refused
with *"This bill has not been released for payment yet. It is with Internal Audit for review."* The
refusal is correct; the sentence is wrong about why. I did not check whether any arm pins the message,
so whether that is covered is UNKNOWN.

### Requirement 4, both halves

**Never the parent — satisfied.** Six surfaces checked above; `guardian` holds no activity-log ability;
the two payment routes and the one read route all exclude void and unreleased bills.

**Finance and Internal Audit only — NOT satisfied, in both directions.** Restating §E's measurement
because it is requirement 4's actual answer: no Finance role holds `activity_log.view` or
`activity_log.view_all`, so Finance cannot read the return history at all; and `teacher` holds
`activity_log.view`, so a role that is neither Finance nor IA can read the API. Whatever carries the
correction history, **the activity log alone does not satisfy requirement 4 today.**

> **[CORRECTED 2026-09-06.]** *"Finance cannot read the return history at all"* is too wide:
> `accounts_officer` reads the current return on its own screen, though no Finance seat reads the
> HISTORY. *"A role that is neither Finance nor IA can read the API"* is true of the door and false
> of the rows FOR `teacher`, which is the seat named — but true of both for `admin`,
> `head_of_school` and `admin_viewer`, which hold `view_all`. The last sentence survives unchanged:
> the activity log alone does not satisfy requirement 4, because it is the only carrier of the
> history and no Finance seat can reach it. See the correction section near the top.

---

## THE CONSTRAINTS

Derived from what is measured above, not from what seems sensible.

1. **A correction that writes `InvoiceStatus::Void` must be added to BOTH lists in
   `tests/Arch/InvoiceVoidHasOneWriterTest.php`** — `voidWriterPermittedFiles()` at `:185-192` and
   `voidWriterPermittedClasses()` at `:199-204`. Demonstrated in §H: the gate reds until it is, and the
   assertion count rises when it is.
2. **If it voids, it must post a reversal.** There is no ledger-level source-uniqueness
   (`ApproveVoidRequest.php:26-27`); a `status = 'void'` written without `SubledgerPoster::post()`
   leaves the original charge standing against a bill that reads void, and the student's balance keeps
   it.
3. **If it voids, the one-way transition must be re-checked under a row lock.** That check
   (`ApproveVoidRequest.php:57,:60-62`) is the ONLY duplicate-reversal guard in the system.
4. **The eligibility re-check is not optional and its authority is the approve-time one.** `SubmitVoidRequest.php:22-29`
   makes the submit-time check advisory in intent; `ApproveVoidRequest.php:64-68` decides. A correction
   that voids a bill with an allocated payment or an approved credit note reverses money that settled.
5. **The replacement is unreleased by construction and requires nothing new to make it so.**
   `GenerateInvoice` never writes `reviewed_at`, so a freshly raised bill has it NULL and is invisible
   to all three parent surfaces (§I). Requirement 2's *"before the parent sees anything"* is already
   the default; what does not exist is anything marking the replacement as *a resubmission* rather than
   an ordinary new bill.
6. **A period decision must be made at two places for a correction, not one** (§F): the reversal's,
   which is `ApproveVoidRequest::originalChargeEffectiveAt()` (`:117-128`) and is a named method, and
   the replacement charge's, which in `GenerateInvoice` is the hardcoded literal `SchoolDay::today()`
   at `:327` with no parameter and no seam.
7. **The slot frees by recomputation of a generated column at the moment `status` is written**, not by
   a release step (§B). Any ordering that raises before voiding collides with
   `UNIQUE(school_id, active_enrollment_key)` for a scheduled bill; any ordering that voids before
   raising must accept whatever window that opens — a window nothing in the suite characterises.
8. **The ADR 0051 seam is the only sanctioned place for "does this need a second signature."**
   `ApprovalRequirement::for()` fails closed (`:43-46`) and every `Submit*.php` action must consult it
   or `bin/ci-boundary-lint.php`'s `approval-seam-missing` reds; `approval-seam-count` requires the
   count of `Submit*.php` actions to equal the count of finance `*_SUBMIT` permissions (ADR 0051
   `:90-95`). A correction action named `Submit*` inherits both lints; one not so named inherits
   neither.
9. **No configuration of that seam may ever let one role hold both sides of a pair** — ADR 0051
   `:82-86`. Requirement 1 removes the *second signature*; it cannot merge two signatures into one
   person.
10. **A new ability terminating in `approve` or `reject` acquires three mechanical consequences
    automatically** (§G): exclusion from the super-admin bypass, duty-separation pairing via
    `matchingMakerFor()`, and — if its maker is not `…submit` — an obligatory `MAKER_OVERRIDES` entry
    that will red `SuperAdminMatrixTest`'s distinct-maker assertion if it names a second pre-convention
    maker. An ability terminating in neither acquires none of them.
11. **`accounts_officer` holds `finance.invoice.generate`**, so it cannot also hold a checker ability
    paired to it under the C6 grant guard. It is also the sole holder of
    `finance.invoice.void-request.submit`.
12. **Requirement 3's "what the bill said before and says now" has no substrate.** Nothing links a
    voided invoice to its replacement (§E, three instruments, both controls). The repository's existing
    idiom for this is a nullable `supersedes_<thing>_id` LOOKUP column written by the approving action
    in one transaction (`ApproveDiscountPolicyChange.php:119`, `ApproveFeeScheduleChange.php:93`).
13. **Requirement 4 is not satisfied by the activity log as currently gated.** No Finance role holds
    `activity_log.view` or `activity_log.view_all`; `teacher` holds the former. Any history placed
    there is invisible to the audience the requirement names and visible to one it excludes.
    **[CORRECTED 2026-09-06 — both halves stand; the EXAMPLE is wrong. `teacher`'s read is
    self-filtered to its own acts and it can cause no finance act, so it is not the seat that proves
    the point. `admin`, `head_of_school` and `admin_viewer` hold `view_all`, are not self-filtered,
    and are neither Finance nor Internal Audit. See the correction section near the top.]**
14. **`reviewed_at` and `returned_at` both set is refused by no database object** —
    `2026_09_04_100000:60-69`. `ApproveInvoice.php:174` closed the release direction in application
    code; the trigger arm the migration names as the other option was not added. A correction flow that
    writes either column inherits this gap.
15. **A resubmission must clear `returned_at`, or it must not** — and whichever it is, the pairing
    trigger (`2026_09_04_100000:197-204`) constrains the shape: `returned_at` may only be written
    together with both companions, in a single statement. `ReturnInvoice.php:24-35` states that the
    trigger makes the three-column array *the only shape the database accepts*.
16. **Non-emptiness of a reason is the action's job, presence is the schema's** —
    `2026_09_04_100000:89-94`, made good on at `ReturnInvoice.php:154-165` with `mb_strlen` against
    `REASON_MAX = 255` (`:126`), refusing rather than truncating. A correction capturing a reason
    inherits the same split.
17. **Money columns on an issued invoice are immutable at the database.** `finance_invoices_total_immutable`
    (`2026_07_19_120000`) denies any edit of the money columns, and `GenerateInvoice.php:36-40` states
    the total cannot drift after the snapshot. **A correction cannot amend a bill in place**; it can
    only produce a new one.
18. **The parent surfaces need no new predicate** (§I). Three routes, all already excluding void and
    unreleased. But `mayPay()` (`GuardianPaymentAuthorisation.php:49-52`) carries none of this, so a
    new parent-facing read that does not route through `InitiateGatewayPayment::assertPayable()`
    inherits no protection.

---

## THE OPEN QUESTIONS

### (i) Brookstone's

1. **Which accounting period does a correction post to?** *Named as a seam, deliberately unanswered.*
   The measured shape of the question: a correction produces a reversal AND a replacement charge, and
   the system already dates them by different rules today. A void's reversal takes the ORIGINAL
   charge's `effective_at` (`ApproveVoidRequest.php:102,:117-128`) on the stated reasoning that the
   bill should never have existed. A newly raised charge takes today (`GenerateInvoice.php:327`). A
   credit note — the other negative posting — takes today (`ApproveCreditNote.php:118`), and therefore
   already disagrees with the void. Their answer determines whether the pair nets to zero in the
   original period, in the current one, or asymmetrically.
2. **Does a correction re-open the return, or close it?** Requirement 2 says the corrected bill returns
   to IA for sign-off. It does not say whether `returned_at` / `return_reason` are cleared on
   resubmission, kept as the standing instruction until sign-off, or copied forward. The columns are
   current state and the activity row is history (`ReturnInvoice.php:99-103`), so this decides what the
   Finance queue shows between resubmission and sign-off.
3. **How many times may one bill be corrected?** A second return overwrites all three columns
   (`ReturnInvoice.php:100-102`). If a correction chain is possible, requirement 3's *"what the bill
   said before"* is a chain, not a pair.
4. **Who inside Finance may correct?** Requirement 1 removes the ED. It does not say whether the
   original raiser may correct their own bill, or whether a second Finance pair of eyes is wanted.
   Measured constraint: `accounts_officer` is the only non-admin holder of `finance.invoice.generate`,
   so "the raiser" and "another Finance person who can raise" are the same role today.
5. **Is the correction reason payer-visible, ever?** Requirement 4 says the history is Finance-and-IA
   only. Recorded because `return_reason` is operator free text with no declared audience — the same
   shape as `note` on an invoice line, and an audience cannot be retro-fitted onto text that already
   exists.

### (ii) The project lead's — engineering rulings

6. **May a correction skip the `VoidRequest` table?** §C enumerates 22 guards it would thereby skip.
   The three with the sharpest consequences are #16 (the only duplicate-reversal guard), #17 (the
   authoritative payment re-check), and #19 (the reversal itself). The question is not whether to skip
   the *approval* — requirement 1 settles that — but whether to skip the *record*, which is a different
   thing and carries #8 through #11 and #18 with it.
7. **Where does the old→new link live?** Options, with costs, choosing none:
   - a nullable `supersedes_invoice_id` LOOKUP on `finance_invoices`, matching the two existing
     instances of this idiom. Cost: a migration on the money table, and a column that is NULL for every
     row that exists.
   - a separate correction table carrying both ids plus the reason and the actor. Cost: a fifth
     invoice-adjacent table and a join on every history read; benefit: it can carry a chain and a
     reason without touching `finance_invoices`.
   - activity-log properties only. Cost: measured in §E — the audience is wrong in both directions
     today, and the log is not queryable as a link.
   *Opinion, labelled as opinion:* the first matches the repository's own precedent most closely and
   is the smallest change; the second is the only one that expresses a chain without overwriting.
8. **Does the correction ability terminate in `approve`/`reject` or not?** §G's constraint 10. This is
   a naming decision with three automatic mechanical consequences, and it is not reversible cheaply
   once granted.
9. **Does the reversal's period rule move, or does the correction get its own?**
   `ApproveVoidRequest.php:90-91` says in the file that the current choice is *"flagged in this
   branch's report for the project lead to overturn"*. Brookstone's answer to (i)(1) may or may not
   require changing the existing void's behaviour as well as the correction's.
10. **Is `bin/ci-boundary-lint.php`'s `approval-seam-count` satisfied?** If a correction action is named
    `Submit*` it must have a matching finance `*_SUBMIT` permission, and vice versa (ADR 0051 `:90-95`).
    A correction that submits nothing but is named `Submit*` breaks the count; one named otherwise
    escapes `approval-seam-missing` entirely.
11. **Should the `reviewed_at` + `returned_at` trigger arm be added now?** `2026_09_04_100000:60-69`
    named the ruling as owed by *"the commit that adds the return action"*. That commit shipped
    (`ReturnInvoice`), and the arm did not. A correction flow is the second commit to inherit the gap.
12. **Does requirement 4 need an RBAC change or a different surface?** Measured: no Finance role holds
    any activity-log ability, and `teacher` holds `activity_log.view`. Options: grant Finance an
    activity-log ability (widens what Finance sees far beyond invoices); build a per-invoice history
    read gated on `finance.access` (a new surface, but scoped exactly); or accept that Finance sees the
    current-state columns only and not the history. Choosing none. *Opinion, labelled as opinion:* the
    first is the largest blast radius for the smallest amount of the requirement.
13. **Should void-then-re-raise in one transaction be characterised before anything depends on it?**
    §B's window is UNVERIFIED and nothing in the suite covers it. If a correction is meant to be atomic,
    that is a precondition, not a detail.

---

## RECORD, DO NOT PROPOSE

This document chooses nothing. Where options exist they are named with their costs in the section
above and left open. Three statements in it are marked as opinion and nowhere else; two —
§B's single-transaction window and §I's refusal-message residual — are marked UNVERIFIED and UNKNOWN
respectively rather than reasoned to a conclusion. The period question is carried as a seam in §F and
answered nowhere.
