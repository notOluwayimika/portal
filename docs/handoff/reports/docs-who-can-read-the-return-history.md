# Implementation report — `docs/who-can-read-the-return-history`

## Headline

**Done with deviations, re-measured once after a first cold review found the first pass stale, and
corrected again after a second review.** Four items: the requirement-4 readership re-measured and the merged investigation corrected, two tickets
opened, one stale migration docblock paragraph rewritten. Branch
`docs/who-can-read-the-return-history`, **rebased onto `origin/staging` @ `97555b24`** (it was cut
from `f22e86fb` and was 7 commits behind). No behaviour changed; one PHP file touched and the diff
is inside a docblock.

**Two cold reviews have run against this branch.** The first, against the pre-rebase pass, produced
the rebase and the re-measure. The second produced one real citation fix and one false positive whose
cause was a procedure defect — both recorded in §"The second review's two findings" and the procedure
fixed in `.claude/skills/finance-review/SKILL.md`.

**This is full-review tier — a cold session before merge is still recommended.** The product is durable claims about RBAC grants, route reachability and a fixture
oracle, written into a merged document. Its diff is documentation; its content is not.

## Deviations from the brief

**Four. The first is mine and it is the serious one; the next two are corrections to the brief's own
premises; the fourth follows from the third.**

### 1. I did not run `bin/board` at task start, and the branch was 7 commits behind

CLAUDE.md's first operational instruction is *"Before starting any task: run `bin/board`, READ THE
DIVERGENCE SECTION"*, with the scar attached — work built against a base somebody had already moved.
I branched from `f22e86fb`, measured everything against it, and wrote the report. **The cold
reviewer found it**, and running the board afterwards confirmed it exactly:

```
ON origin/staging, NOT IN docs/who-can-read-the-return-history
  7 commit(s) on origin/staging that this branch does not have:
    97555b24 Merge pull request #420 from notOluwayimika/feat/superadmin-user-provisioning
    ...
    7a08b8bf feat(rbac): add admin_viewer, a read-only admin seat with its own door
```

`7a08b8bf` adds a SIXTEENTH role holding four of the seven activity-log abilities. **Every number in
the first pass's matrix was wrong, and every audit-door list was short a seat** — in a correction
whose subject is who can read an audit log.

**What makes it worth writing up rather than just fixing:** nothing in the first pass could have
caught it. `tests/fixtures/route-access-map.json` holds **384 routes at both commits** — 0 added, 0
removed — while 121 of its role lists moved. So *"384 routes EXAMINED"* was byte-identical before and
after. **A denominator that holds while the content moves is not a freshness check**, and this is the
first instance in this repository's notes of a coverage number being stable across exactly the drift
that invalidates the answer. The correction now states the base SHA it was measured at, so the next
drift is visible rather than silent.

The branch has since been rebased onto `origin/staging` and contains it —
`git merge-base --is-ancestor origin/staging HEAD` exits 0.

### 2. The brief's premise for item 1 is wrong, in both of its halves

The brief said the document's figures were *"a measurement of the WRONG OBJECT"* because
`RbacSeeder.php:563` names a convergence migration that *"GRANTS TO AN EXISTING ROLE OUTSIDE THE
MAP"*, so `grantsMap()` *"cannot answer who holds this"*.

**Measured, and false twice** (line numbers re-derived at `97555b24`; the brief's `:563` is now
`:624`):

1. `RbacSeeder.php:624` is a COMMENT — *"Converged by
   2026_09_01_120000_grant_internal_auditor_activity_log_view_all."* — annotating `:625`, which IS
   `PermissionEnum::ACTIVITY_LOG_VIEW_ALL->value`, inside the `internal_auditor` block opening at
   `:610`. The grant is in the map. The convergence migration exists because `rbac:sync` is
   non-destructive for an already-existing role, not because the map lacks it.
2. At the ORIGINAL base, `rbac:diff-grants` reported `CLEAN`, exit 0, with 0 school-scoped role rows
   — map and database identical.

**The suspicion was nonetheless right, and after the rebase it BITES — in the opposite direction to
the one the brief expected.** At `97555b24` the same command exits **1**: the tree has
`admin_viewer` and this database does not, because nobody has run `rbac:sync` since PR #420. **Here
the DATABASE is the stale object and the map is the fresh one.** The correction reports both,
labelled, and says the tree is authoritative because it is what every environment holds after a
deploy.

### 3. The brief's premise for item 4 is wrong-shaped

The brief carried the migration's *"the instant one unpaired row exists, it could never be added
without a data fix first"* as an argument that arm (a)'s cost *"rises further with every row"*.

**That sentence is about the PAIRING guard, not about arm (a)** —
`2026_09_04_100000:54-58`, where *"the guard below"* is the
`returned_at`/`return_reason`/`returned_by_user_id` trigger installed ~120 lines later. The shape
transfers; the cost curve does not. The pairing guard's free window closes when anything writes a
`returned_at`. **Arm (a)'s closes only when a BOTH-SET row exists** — and both writers refuse to
create one, with a test arm pinning both refusals. Arm (a) does not get dearer daily.

### 4. The ticket is therefore renamed

`the-both-set-guard-is-php-only-and-nobody-ruled-it-final.md`, not the brief's
`…-and-arm-a-gets-dearer-daily.md`. A ticket filename is a claim other documents cite for years, and
this repository's rule is that a description which quantifies must be true or be weakened. The
ticket records the rename and why, in its second paragraph; the migration docblock cites the new
path.

### 5. The correction's internal cross-references are anchors, not line numbers

I first cited the four wrong passages by line. Inserting the correction invalidated all four;
adding the inline markers invalidated them again; the rebase and the re-measure moved them a third
time. **A document citing its own line numbers is stale on write.** They are now named by heading and
quoted phrase, and each passage carries an inline `[CORRECTED 2026-09-06]` marker pointing back.

## Contradictions of the premise

**The substantive one, which the brief did not name and which is bigger than the RBAC question.**

The merged document says, in four places, that *"no Finance role holds `activity_log.view` or
`activity_log.view_all`, so Finance cannot read the return history at all"*, and that `teacher`'s
grant means *"a role that is neither Finance nor IA can read the API"*.

**The grant measurement is right. Three things drawn from it are not.**

1. **Finance is not blind to the return.** `GET /finance/returned-bills` (`routes/web.php:355-357`)
   and `GET /api/v1/finance/invoices/returned` (`routes/endpoints/finance.php:495-496`), both gated
   on `permission:finance.invoice.generate`, are reached by `accounts_officer`, `admin` and
   `super_admin`. `ReturnedInvoiceQueueController.php:190-192` puts `returned_at`, `returned_by` and
   `return_reason` into the payload. **But it is ONE Finance seat of four** —
   `finance_lead`, `accounts_supervisor` and `executive_director` reach neither door, so for them the
   original sentence is exactly right. The correction names seats rather than the department.
2. **The `teacher` example is wrong and the claim it supported is right.** `teacher` lacks
   `view_all`, so `ActivityLogQueryService.php:55-57` self-filters it to rows it caused, and it can
   cause no finance act. But `admin`, `head_of_school` and — since `97555b24` — **`admin_viewer`**
   hold `view_all`, are not self-filtered, and are neither Finance nor Internal Audit. So finding 13
   is correct as a claim and wrong in its example. `admin_viewer` is the sharpest instance: a new
   read-only oversight seat that also holds `activity_log.view_sensitive` and can read every return
   on both doors.
3. **The mechanism I first offered for the history loss was itself an unmeasured inference**, and
   the reviewer caught it. I wrote that a bill *"leaves the screen when corrected, re-released or
   voided"*. Measured, **only the void exit exists**: `grep -rn "'returned_at' =>" app/` finds one
   writer (`ReturnInvoice.php:192`, writing `now()`) and nothing writes NULL; the correction verb
   does not exist at all, which `ReturnedInvoiceQueueController.php:32-35` says out loud; and
   re-release is refused (`ApproveInvoice.php:273`, called `:163`/`:185`, predicate `:174`).
   Likewise *"a second return overwrites the column"* — true as the repo's own forward-looking
   comment (`ReturnInvoice.php:222-224`), refused today by `refuseIfAlreadyReturned` (`:279`, called
   `:182`) and the CAS at `:189`. Both are now stated at their measured size.

**The method error is the reusable part and it is in the correction:** a gate measurement answers
*who holds this permission*; it never answers *who can see this fact*. A capability is satisfied by
any surface, and the search for other surfaces was never run.

## What changed

Six files, one commit.

| file | what it does |
| --- | --- |
| `docs/handoff/what-correct-returned-invoice-must-satisfy.md` | a dated correction section after "Corrections to the brief" — the base SHA it was measured at and why that matters, the three corrections, the map-vs-database result in both directions, the re-measured matrix (intent AND reality, labelled) with three coverage numbers, the seat-by-seat answer, the teacher trace — plus four inline `[CORRECTED 2026-09-06]` markers |
| `docs/handoff/tickets/the-void-path-writes-no-activity-row.md` | new — the void trio writes no activity row; what a reader loses and what they do not; why requirement 3 makes it now; three closures proposed, none chosen |
| `docs/handoff/tickets/the-both-set-guard-is-php-only-and-nobody-ruled-it-final.md` | new — the both-set state is refused in PHP at two writers and by no schema object; both positions recorded, neither chosen; the freeness argument carried and corrected |
| `database/migrations/2026_09_04_100000_finance_invoices_return_to_finance.php` | the `BOTH SET` paragraph (`:60-84`) rewritten from open-question tense to landed tense. **Comment only** — `up()`, `down()`, columns, triggers and constants untouched |
| `.claude/skills/finance-review/SKILL.md` | **one added instruction and its reason** under § "Your scratch, their scratch": take a review clone's `origin` from the authoritative remote, or use a worktree. Nothing else in the skill is touched |
| `docs/handoff/reports/docs-who-can-read-the-return-history.md` | this file |

Exact insertion counts are deliberately not quoted here: a report cannot state its own final length,
and the counts moved twice during the re-measure. Re-derive with `git show --stat HEAD`.

## Proof

### 1 — HEAD, before anything

```
staging
f22e86fb Merge pull request #421 from notOluwayimika/docs/what-correct-returned-invoice-must-satisfy
d1f0e0b9 docs(finance): measure what a correction to a returned invoice must satisfy
---END-STATUS---
```

`git status --porcelain` produced NO lines before the sentinel. The sentinel is there because an
empty porcelain and a swallowed porcelain look identical. Expected `staging` @ `f22e86fb`, clean;
observed exactly that. **What I did NOT do at this point was run `bin/board` — see deviation 1.**

### 2 — the board, run late, and the rebase

```
ON origin/staging, NOT IN docs/who-can-read-the-return-history
  7 commit(s) on origin/staging that this branch does not have:
    97555b24 Merge pull request #420 from notOluwayimika/feat/superadmin-user-provisioning
    e4cb3265 Merge pull request #411 from notOluwayimika/chore/drive-fixture-has-a-payer
    faf55fbf feat(rbac): user management listing — search, filters, pagination; clear the gate
    825bbdea feat(rbac): super-admin user provisioning across roles and schools
    7a08b8bf feat(rbac): add admin_viewer, a read-only admin seat with its own door
    0fe6037a chore(drive): a released bill a parent can read, and a seat table that names every seat
    9783beb8 chore(drive): the fixture gains a payer, three wards, and a column that would have said so
```

```
Successfully rebased and updated refs/heads/docs/who-can-read-the-return-history.
REBASE EXIT=0
7fcd158c docs(finance): who can read the return history, re-measured against the database
97555b24 Merge pull request #420 from notOluwayimika/feat/superadmin-user-provisioning
CONTAINED: yes
```

The containment check is `git merge-base --is-ancestor origin/staging HEAD`, exit 0 — so the merge
result and the gated tree would be the same tree, which CLAUDE.md notes nothing enforces.

### 3 — `rbac:diff-grants`, at BOTH bases, and the exit code is the finding

**At `f22e86fb`:** `CLEAN`, **exit 0**, `0 missing, 0 extra across 0 role(s)`, footer 0 school-scoped
rows.

**At `97555b24`, raw:**

```
rbac:diff-grants — RbacSeeder::grantsMap() vs the live grants
 env=local db=portaa10_portal guard=web
 scope: global role rows only (roles.school_id IS NULL)

SECTION A — permission catalog (enum vs `permissions` rows)
 1 declared in the enum, NO permission row:
 - admin_area.view

 BANNER: the catalog does not agree with the enum, which means `rbac:sync` has NOT been
 run on this database. SECTION B IS UNINTERPRETABLE until it is: every `missing` below
 may be explained by that alone. Run `php artisan rbac:sync`, then re-run this command.

SECTION B/C — grants per global role, with the diagnosis for each difference
 clean — every role in grantsMap() holds exactly its mapped grants.

 1 role(s) in grantsMap() have NO global role row — rbac:sync would create them:
 - admin_viewer

FOOTER — school-scoped `web` role rows (school_id IS NOT NULL), counted, NEVER diffed: 0

TOTALS catalog: 1 missing row(s), 0 extra row(s) | grants: 0 missing, 0 extra across 0 role(s) | roles: 1 mapped-without-row, 0 unmapped
 FINDINGS (detection only — nothing was changed)
```

**`rbac:diff-grants EXIT=1`**, captured from `$?` directly, not through a pipe.

**`rbac:sync` was NOT run.** It mutates a production-derived copy and that is the maintainer's call,
not a measurement step. The catalog diff is `missing_rows` only (`1 missing / 0 extra`), which by
`docs/runbooks/rbac-grants-reconciliation.md` §2a is the safe case — recorded as information for
whoever decides, not as a licence taken here.

### 4 — the matrix, both authorities, at `97555b24`

**INTENT — `RbacSeeder::grantsMap()`, executed. 16 roles.**

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

MAP HOLDER COUNTS
  activity_log.view                    roles=5
  activity_log.view_all                roles=4
  activity_log.view_own                roles=4
  activity_log.view_system             roles=1
  activity_log.view_cross_school       roles=1
  activity_log.export                  roles=3
  activity_log.view_sensitive          roles=3
  finance.access                       roles=6  <= POSITIVE CONTROL
  activity_log.zzz-no-such-ability     roles=0  <= ABSENT CONTROL
```

**REALITY — `role_has_permissions` on `db=portaa10_portal`. 15 roles, 15 global, 0 school-scoped.**
Identical except that the `admin_viewer` ROW DOES NOT EXIST, so:

```
DB HOLDER COUNTS
  activity_log.view                    roles=4
  activity_log.view_all                roles=3
  activity_log.view_own                roles=3
  activity_log.view_system             roles=1
  activity_log.view_cross_school       roles=1
  activity_log.export                  roles=3
  activity_log.view_sensitive          roles=2
  finance.access                       roles=6  <= POSITIVE CONTROL
  activity_log.zzz-no-such-ability     roles=0  <= ABSENT CONTROL

model_has_permissions rows (ALL abilities) = 0
UNRECOGNISED activity_log.* permission rows = 0 []
```

(Column whitespace realigned for reading; every value as printed.)

**Coverage, three numbers.** EXAMINED 16 of 16 mapped roles / 15 of 15 database roles × 7 of 7
`activity_log.*` enum values (`app/Enums/Permission.php:55-61`). EXCLUDED with a stated reason: **1**
— the `api`-guard `super_admin` row (`RbacSeeder.php:19-21`), outside `guard_name = 'web'` by
construction. **UNRECOGNISED: 0**, asserted by a direct query for `activity_log.%` permission rows
outside the seven rather than inferred from a clean run.

**Why the tree is the authority.** `grantsMap()` and the two derived oracles are what every
environment holds after a deploy, and are what the oracles regenerate from; a database mid-deploy is
one environment's transient state. Both are reported, labelled, because collapsing them is how a
stale number becomes a confident assertion. `model_has_permissions` is checked separately because
neither authority sees it; `Gate::before` separately because no grant table can.

### 5 — the doors, from the derived reachability oracle at `97555b24`

384 routes EXAMINED, 11 matched `activity-log`, absent control `zzz-no-such-route` = 0, positive
control `/api/` = 276.

```
GET /activity-logs                       => ['admin','admin_viewer','head_of_school','internal_auditor','super_admin']
GET /activity-logs/{id}                  => ['admin','admin_viewer','head_of_school','internal_auditor','super_admin']
GET /api/activity-logs                   => ['admin','admin_viewer','head_of_school','internal_auditor','super_admin','teacher']
GET /api/activity-logs/export            => ['admin','head_of_school','internal_auditor','super_admin']
GET /api/activity-logs/{id}              => ['admin','admin_viewer','head_of_school','internal_auditor','super_admin','teacher']
GET /api/activity-logs/stats             => ['admin','admin_viewer','head_of_school','internal_auditor','super_admin','teacher']
GET /api/activity-logs/filters/options   => ['admin','admin_viewer','head_of_school','internal_auditor','super_admin','teacher']
GET /api/activity-logs/exports/{export}  => ['admin','admin_viewer','head_of_school','internal_auditor','super_admin','teacher']
GET|POST|DELETE /api/activity-logs/saved-filters[...] => [..., 'teacher']

GET /finance/returned-bills              => ['accounts_officer','admin','super_admin']
GET /api/v1/finance/invoices/returned    => ['accounts_officer','admin','super_admin']
GET /internal-audit/review-queue         => ['internal_auditor']
POST /api/internal-audit/invoices/{uuid}/return => ['internal_auditor']
```

Two things to read off it. **`admin_viewer` is on both audit doors and NOT on export** — the
derivation excludes `activity_log.export` by construction and admits `view_sensitive` deliberately
(`RbacSeeder.php:167-170`). **`super_admin` reaches both doors holding NEITHER permission**, which is
`Gate::before` — `app/Providers/AppServiceProvider.php:127` (`registerSuperAdminGate`), bypass arm
at `:132`, `config('auth.gate_before_superadmin') && $user->isSuperAdmin()` — and is why the grant
matrix and the oracle disagree by one role. **The admission is conditional on that flag**, which
`config/auth.php:133` defaults to `true` via `AUTH_GATE_BEFORE_SUPERADMIN`;
`app/Support/RouteAccessMap.php:24-26` names the dependency and says flag-off *"admits only actual
holders"*, so with it off `super_admin` reads nothing on either door.

### 6 — the five `activity(` counts, with controls

**EXAMINED: 5 action files, and separately all 199 PHP files under `app/Finance` — which is what
turns this from a spot check into an absence claim.**

```
app/Finance/Actions/ApproveInvoice.php    : activity( = 1 (grep exit 0) : lines=289
app/Finance/Actions/ApproveVoidRequest.php: activity( = 0 (grep exit 1) : lines=129
app/Finance/Actions/RejectVoidRequest.php : activity( = 0 (grep exit 1) : lines=38
app/Finance/Actions/ReturnInvoice.php     : activity( = 1 (grep exit 0) : lines=293
app/Finance/Actions/SubmitVoidRequest.php : activity( = 0 (grep exit 1) : lines=96

POSITIVE CONTROL  'class'             : 1, 1, 1, 2, 1  — the matcher reads all five files
ABSENT   CONTROL  'zzzNoSuchTokenXyz' : 0, 0, 0, 0, 0  — it is not matching everything
```

The three zeros are `grep` **exit 1**, not a silent 0.

Whole-context sweep — 6 code call sites, 0 unrecognised (a seventh `grep` hit at
`StudentDiscountAward.php:86` is a comment and is excluded):

```
app/Finance/Http/Controllers/BankAccountController.php:188:        activity('finance')
app/Finance/Actions/ApproveInvoice.php:197:            activity('finance')
app/Finance/Actions/SetSettlementBankAccount.php:113:                activity('finance')
app/Finance/Actions/AwardStudentDiscount.php:274:        $logger = activity('finance')
app/Finance/Actions/ReturnInvoice.php:214:            activity('finance')
app/Finance/Actions/SettleGatewayTransaction.php:416:            activity('finance')
```

**The absence is genuine, not displaced.** `grep -c LogsActivity` = **0** on both
`app/Finance/Models/Invoice.php` and `app/Finance/Models/VoidRequest.php`, against **4** on
`app/Finance/Models/StudentDiscountAward.php` as positive control — the only Finance model carrying
the trait, of 38 users across `app/`. `app/Providers/AppServiceProvider.php:106` registers the
application's only observer and its subject is `StudentCurriculum`.
`app/Finance/Http/Controllers/VoidRequestController.php` and
`app/Finance/Services/SubledgerPoster.php` both count 0.

### 7 — item 3's line numbers, mine against the brief's

The brief said *"ApproveInvoice.php:174 plus refuseIfOutWithFinance at :163 and :185, with an
absent-control at 0."* **All three correct, and unchanged by the rebase.** Raw:

```
163:            $this->refuseIfOutWithFinance($locked);
174:                ->whereNull('returned_at')
185:                $this->refuseIfOutWithFinance($fresh);
```

absent control `returned_atZZZ` = 0. The paragraph the brief asked me to re-derive was `:60-69`
before the edit and is `:60-84` after it.

**Line numbers the brief did not have, and one matters.** The MIRROR guard,
`app/Finance/Actions/ReturnInvoice.php:190` (`->whereNull(Invoice::RELEASE_STAMP_COLUMN)`), with its
read-side `refuseIfAlreadyReleased` called at `:181` and defined at `:243`. **Option (b) shipped in
BOTH directions**, and a docblock naming only the approve side would have been half-true.

**And every `RbacSeeder` and `routes/` line number in the first pass moved under the rebase**, which
is the "carry no number" rule demonstrating itself: `$activityStaff` 140→201, `$activityAdmin`
145→206, `internal_auditor` 549→610 (its `view_all` 564→625, the convergence comment 563→624),
the seeder's `Gate::before` COMMENT 207→268, `routes/api.php` gate 375→386, `routes/web.php` gate
1062→1081. All re-derived and corrected in the shipped text.

### 8 — the sensitivity filter, enumerated rather than assumed

`config/activity_log_sensitive.php:27-49` declares **12** entry patterns. Two begin `finance.` —
`finance.fee_adjusted` (`:30`), `finance.refund_issued` (`:31`) — and the only wildcard is
`permissions.*` (`:41`). `finance.invoice.returned` matches none, so `excludeSensitive` does not hide
it from `internal_auditor`, which lacks `view_sensitive`. Absent control on the pattern list: 0.

### 9 — gates

```
citation-lint: OK — no new citation violations (164 baselined key(s), 181 citation(s)).   EXIT=0
pint --test on 1 changed PHP file: {"tool":"pint","result":"passed"}                      EXIT=0
```

Pint was invoked through the ARRAY form with the empty-list guard, per CLAUDE.md; `${#files[@]}`
printed **1**, so the guard did not fire and the instrument examined a file.

**AND THE GATE'S REACH IS NARROWER THAN THIS CHANGE, WHICH THE FIRST PASS DID NOT SAY.**
`bin/ci-citation-lint.php:212` — `SCANNED_DIRS = ['app','tests','bin','database','config','routes','bootstrap','.claude/skills']`
— and its own header at `:91` says `NOT SCANNED: docs/`. **Four of the six changed files live under
`docs/` and are read by no gate at all**, and they are the files whose content is almost entirely
`path:LINE` citations. The other two — the migration and
`.claude/skills/finance-review/SKILL.md` — ARE scanned (`database` and `.claude/skills` are both in
`SCANNED_DIRS`), and both were bite-proven below. The `164 baselined / 181 citations` figure is true and is about a different
set of files. Every citation in the four documentation files was verified by hand against the tree
at `97555b24` — the `RbacSeeder`, `routes/`, `ActivityLogQueryService`, `ActivityLogController`,
`ReturnInvoice`, `ApproveInvoice`, `ReturnedInvoiceQueueController`, `config/activity_log_sensitive`,
the two void-request migrations and the two cross-referenced doc anchors — and the re-derivations are
in §7 above. That is a hand check, not a gate, and it is stated as one.

Prettier and ESLint were not run: `bin/lint-changed.sh:46` routes only `resources/*` to Prettier and
this change adds none. Markdown is linted by nothing here.

**The suite was not run.** No application code changed, so there is nothing for it to measure, and a
run would hold `portal_testing` for ~18 minutes to re-measure `staging`.

## The watched red

**The citation lint, both arms.** It is the only gate this change can bite, and it went green first
try — the least trustworthy green a gate produces.

**PLANT**, into the migration docblock (a SCANNED directory — see §9 for why that matters):

```
 * PLANT: `app/Finance/Actions/ApproveInvoice.php:174 (zzzNoSuchSymbolPlanted)`
```

```
PLANTED -> citation-lint EXIT=1
citation-lint: 1 NEW or GROWN citation violation(s) — a citation must name a symbol, and the symbol must be there:
  ✗ database/migrations/2026_09_04_100000_finance_invoices_return_to_finance.php  app/Finance/Actions/ApproveInvoice.php:174  [citation-symbol-not-found]
```

**The message names the right thing**: rule `citation-symbol-not-found` (the symbol is absent), not
`citation-missing-symbol` (no symbol named). Two different rules, and the one that fired is the one
the plant should trigger — *"it threw"* would have been satisfied by either.

**RESTORE:**

```
RESTORED -> citation-lint EXIT=0
citation-lint: OK — no new citation violations (164 baselined key(s), 181 citation(s)).
RESTORE VERIFIED: file identical
```

Restored by `cp` from a copy held outside the repository, verified with `diff -q` rather than by
assertion. **The known-negative arm is the one that matters**: a lint that refused everything would
look identical to a strict one, and only the free arm distinguishes them.

**SECOND PLANT, in the OTHER scanned directory this commit touches.** The first plant proves the gate
reaches `database/`; it says nothing about `.claude/skills/`, which this commit newly edits. So the
same two arms were run there — the plant carrying a real file and line with a symbol that is not at
it:

```
PLANTED -> EXIT=1
  ✗ .claude/skills/finance-review/SKILL.md  app/Providers/AppServiceProvider.php:127  [citation-symbol-not-found]

RESTORED -> EXIT=0
citation-lint: OK — no new citation violations (164 baselined key(s), 181 citation(s)).
RESTORE VERIFIED: identical
```

Same rule name, and the file named in the violation is the one that was planted in — so the gate
examined the new file rather than merely staying green about the old one. **The gate's reach over
each changed directory is established separately**, because a green from one says nothing about
another; that is the same question as *what did the instrument examine*, asked per-directory.

## Database observations

Under the privacy rule — structure and totals, no rows and no names.

| observation | at `f22e86fb` | at `97555b24` |
| --- | --- | --- |
| roles in `RbacSeeder::grantsMap()` | 15 | **16** |
| `web`-guard role rows in the database | 15 (15 global, 0 school-scoped) | 15 — unchanged; `admin_viewer` has no row |
| `rbac:diff-grants` | CLEAN, exit 0 | **1 mapped-without-row, 1 catalog missing, exit 1** |
| `model_has_permissions` rows, ALL abilities | 0 | 0 |
| `activity_log.%` permission rows outside the enum's 7 | 0 | 0 |
| migrations replayed | 179 of 181 | 179 of 181 — PR #420 added no migration |
| `finance_invoices` / `finance_void_requests` rows | 0 / 0 | 0 / 0 |
| `activity_log` rows | 180,952, of which `log_name='finance'` = **0** | unchanged |

**This branch writes nothing to the database.** Every read went through `php artisan tinker
--execute` against `db=portaa10_portal`. No `mysql` client, no root connection, and `rbac:sync` was
not run.

**The environment caveat, because it bounds two claims.** The unrun migrations at the original base
were `2026_09_04_100000_finance_invoices_return_to_finance` and
`2026_09_04_110000_finance_invoices_auditor_queue_index`, identified by differencing
`database/migrations/*.php` against the `migrations` table (absent control — rows with no file —
returned **0**). Both are schema-only and neither touches roles, permissions or grants, **so the
matrix is complete for a fully migrated environment.** But `returned_at` does not exist locally, so
the both-set row count is **UNMEASURABLE HERE, NOT MEASURED AS ZERO** — the distinction the ticket
turns on, and why it is written as UNKNOWN.

## Not done

- **No suite run**, reasoned above. If the reviewer disagrees, the file is
  `tests/Feature/Finance/ReturnedInvoiceQueueEndpointTest.php` and `bin/db-exclusive` is how.
- **No drive.** No screen changed.
- **`rbac:sync` not run**, so the database half of the matrix stays one deploy behind the tree. That
  is a maintainer decision, not mine.
- **The both-set row count** on any environment carrying `returned_at` — not obtainable from here.
- **Whether `tests/Arch/ReviewCompareAndSwapCarriesBothPredicatesTest.php` covers a NEW writer or
  only the two that exist.** File confirmed present, docblock quoted, **body not read**. Named in the
  ticket as unmeasured; it is load-bearing for that ticket's position 1.
- **What `admin_viewer` means for the requirement-4 RULING.** I measured that it can read every
  return in full and recorded it. Whether that is acceptable — a read-only oversight seat is arguably
  exactly who should — is a Brookstone decision and I did not take it.
- **Whether any arm pins `InitiateGatewayPayment`'s misleading refusal sentence** — the merged
  document left it UNKNOWN and I did not close it.
- **The three proposed void event keys' severity classification** — a ruling, deliberately not made.

## Findings raised, not fixed

1. **A coverage number can be stable across exactly the drift that invalidates the answer.**
   `route-access-map.json` held 384 routes at both bases while 121 role lists changed. Every "N
   EXAMINED" figure in this repository's reports is a count of the denominator, and a denominator is
   not a freshness check. Suggested general fix: a report quoting an oracle states the SHA it read it
   at. **ticket**
2. **`bin/ci-citation-lint.php:212` excludes `docs/`**, so `docs/handoff/` — this project's densest
   concentration of `path:LINE` citations — is ungated. `:97` records that adding `docs` produces
   1,347 keys / 1,579 citations, so the cost is known and the exclusion is a decision. Worth a
   ticket only to make the decision explicit. **ticket**
3. **`tests/Feature/Finance/ReturnedInvoiceQueueEndpointTest.php:141` and `:146` both assert
   `toThrow(BusinessRuleException::class)` with no message.** `ApproveInvoice` raises it from ≥3
   sites and `ReturnInvoice` from 3 of its own, so neither assertion names the MECHANISM. On the
   fixtures as built the mechanism is determined; this is robustness, not a live hole. **ticket** —
   recorded inside the both-set ticket.
4. **Nothing asserts that a Finance governance action emits an activity row.**
   `bin/ci-activity-catalogue-lint.php` (`bin/quality` step 20, `bin/quality:498`) checks that a
   DECLARED emitter is catalogued; it cannot see an action with no emitter. That asymmetry is why
   the void gap survived to be found by hand. **ticket** — recorded inside the void ticket.
5. **`finance-context` states `bin/quality` is a 15-step script. It is 20.** `grep -c '^\s*step "'
   bin/quality` = 20, and `bin/quality:66` prints `[%d/20]`. The skill warns in that same sentence
   that the number has moved five times; it has moved again. A skill file, not repository code, so
   it is named here rather than edited. **ticket**
6. **`super_admin`'s reach on both audit doors is gated on a config flag, and every prior statement
   of it in this repository's notes reads as unconditional.**
   `app/Providers/AppServiceProvider.php:132` is
   `config('auth.gate_before_superadmin') && $user->isSuperAdmin()`; `config/auth.php:133` defaults
   it `true` via `AUTH_GATE_BEFORE_SUPERADMIN`. `app/Support/RouteAccessMap.php:24-26` already names
   the dependency for the oracle — the gap is in the prose that quotes the oracle. Found only by
   re-deriving a citation the second review flagged for a different reason. **ticket**
7. **The audit API group is gated on `activity_log.view` while the page is gated on
   `activity_log.view_all`** (`routes/api.php:386` vs `routes/web.php:1081`).
   `routes/web.php:1064-1069` records that the page was deliberately raised so *"the GATE AND THE
   QUERY NOW KEY ON THE SAME FACT"*; `routes/api.php:365-384` explains why the API's is `view` —
   narrowing further would lock `internal_auditor` out again. Not a defect and not this commit's to
   decide; half-recorded already at
   `docs/handoff/tickets/audit-seat-has-the-ability-and-no-way-to-reach-it.md`. **ticket**

## The second review's two findings — one citation error, one false positive

A second cold review was run after the rebase. It returned two findings. **One is a real citation
error and is fixed in this commit. One is a false positive, and it is recorded here rather than
dropped, because its cause is a procedure defect that will recur.**

### FINDING A — real. `Gate::before` was cited at the wrong coordinate. FIXED.

**What it claimed.** The report attributed `super_admin`'s presence on both audit doors to
*"`Gate::before` (`RbacSeeder.php:268`)"*. That line is a COMMENT about the bypass, not the
mechanism.

**Confirmed.** `database/seeders/RbacSeeder.php:268` reads
`// super_admin deliberately gets none: its passage is Gate::before.` — a true sentence and the
wrong coordinate for a mechanism. The mechanism is `app/Providers/AppServiceProvider.php:127`
(`registerSuperAdminGate`), whose bypass arm at `:132` is:

```php
if (config('auth.gate_before_superadmin') && $user->isSuperAdmin()) {
    return true;
}
```

**Substance was right; only the pointer was wrong.** Repointed in the report and in the correction
section of the merged document; the seeder comment is kept as a QUOTE, labelled as a comment.

**AND RE-DERIVING IT SURFACED SOMETHING NEITHER THE REVIEW NOR THE BRIEF NAMED: the bypass is
CONDITIONAL.** `config/auth.php:133` is
`'gate_before_superadmin' => env('AUTH_GATE_BEFORE_SUPERADMIN', true)`, so it is
environment-overridable, and `app/Support/RouteAccessMap.php:24-26` states the reachability oracle's
dependency on it outright — super_admin is admitted *"only while auth.gate_before_superadmin is on
[…] flag-off admits only actual holders"*. **With the flag off, `super_admin` reads NOTHING on
either audit door**, holding neither ability. The earlier text asserted an unconditional YES. Both
the report and the correction now carry the condition. This is the *"assert WHICH negative"* rule in
its positive form: a capability claim that holds only under a flag has to name the flag.

### FINDING B — false positive. The base label was correct; the REVIEW's ref was stale.

**What it claimed.** That the base is mislabelled — that `97555b24` is not `origin/staging`, and
that `admin_viewer` therefore rides on an unmerged PR rather than on the shipped base.

**Measured directly, with controls, in the project repository:**

```
git merge-base --is-ancestor 7a08b8bf origin/staging   -> exit=0   (admin_viewer IS on origin/staging)
git merge-base --is-ancestor 7a08b8bf staging          -> exit=1   (it is NOT on the LOCAL branch staging)

POSITIVE CONTROL  f22e86fb in origin/staging -> 0    f22e86fb in local staging -> 0
ABSENT   CONTROL  0a839956 (this branch's own commit) in origin/staging -> 1
```

and the two refs, pasted:

```
git rev-parse origin/staging staging
97555b2436677eaca71ece80ca302154573108b1     <- origin/staging
f22e86fb5fbcbad40145d56b55e7528c84dc386d     <- LOCAL branch staging
```

**So the finding is wrong and the work is right.** `admin_viewer` is on the authoritative remote's
`staging`; the base label `97555b24` is accurate.

**THE MECHANISM, which is the part worth keeping.** The review obtained its isolated tree by
cloning a LOCAL PATH. **Cloning a local path makes that repository the clone's `origin`** — so
`origin/staging` inside the clone resolves to the project's LOCAL BRANCH `staging`, which is
`f22e86fb` and seven commits behind the real remote. The reviewer then measured `admin_viewer`
against that ref and correctly concluded it was absent from what it believed was `origin/staging`.

**Isolation from the working tree was achieved; CURRENCY was silently lost.** There is no error, no
warning and no visible difference — the ref exists, resolves, and answers. This is the same family
as *"a wrapper's exit status is a claim about the wrapper"*: `origin/staging` in a clone is a claim
about **that clone's origin**, not about the remote.

**Why it is recorded rather than dropped.** The review nominated this as its own weakest claim and
named the exact measurement that would settle it. That is the separation working: a review that
states what would falsify it converts a wrong finding into a cheap one. A false positive surfaced
that way is worth more in the record than a quietly deleted one, because the next reviewer inherits
the procedure, not the conclusion.

**FIXED AT THE DURABLE HOME**, not here. `.claude/skills/finance-review/SKILL.md` § "Your scratch,
their scratch" now carries one instruction and its reason — take the clone's `origin` from the
authoritative remote (or use a git worktree, which shares the project's refs), because cloning a
local path silently redefines `origin`; and if the branch under review is unpushed, fetch it from
the local repo as a separate named remote.

**AND THE BRIEF'S ACCOUNT OF WHERE THE INSTRUCTION LIVES IS NOT WHAT THE TREE SAYS.** The brief said
*"the cold-review brief instructs `git clone /Users/mac/Documents/Projects/portal`"*. **No such
instruction exists in this repository.** Swept `git grep "git clone"` over the whole tracked tree —
**2 files**, neither an instruction to a reviewer:

| hit | what it is |
| --- | --- |
| `.githooks/pre-push:147` | a COMMENT — *"a `git clone --local` (which is how reviews are run) carries them"*, explaining why the quality stamp is a git note rather than a gitignored file |
| `tests/Feature/Quality/LandedCheckCoverageTest.php:564` | a test fixture cloning a `file://` origin for the shallow-clone arm of `bin/landed`; unrelated to review isolation |

Positive control: `git grep "git fetch"` matches **13 files** in the same corpus, so the matcher
works. Absent control: `git cloneZZZ` matches **0**.

**So the defect was not a written instruction — it was an UNSPECIFIED one.** `SKILL.md` said *"run
against a fresh clone of the branch"* and said nothing about where to clone FROM, and the only local
source a reviewer has is the project directory. The pre-push comment shows the local-clone practice
was already assumed in the tree. **A procedure with an unstated parameter is filled in by whoever
runs it, reasonably, and wrongly** — which is why the fix is one added instruction rather than a
correction to an existing one.

---

## Note on the attached review

The cold review was run against the FIRST pass, before the rebase. Its two `stop` findings — no
commits on the branch, and the matrix stale against `origin/staging` — are both correct and both
addressed above; the second is the more valuable half and it is the reason this report exists in a
second version. Of its five `fix`/`ticket` findings, four are addressed in the shipped text
(citations `:141`/`:146`, the queue-exit overstatement, the second-return overstatement, the
"Finance HAS it" over-generalisation, and the `docs/` gate gap). **One is a false positive caused by
the review's own correct refusal to read uncommitted work**: its finding 6 says the §E requirement-4
table row is a fifth uncorrected passage. It is not — it is the FIRST of the four, and it carried its
`[CORRECTED 2026-09-06]` marker from the start. The reviewer said explicitly it could not confirm
this, which is the separation working rather than failing.
