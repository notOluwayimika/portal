# Implementation report — `docs/stale-payment-gate-claim`

**Branch:** `docs/stale-payment-gate-claim` (cut from `staging` @ `0672ed8`)
**Brief:** `docs/handoff/stale-payment-gate-claim-brief.md`
**Revision:** second pass, after subagent review. See "Revision after review" below.

## My error, first

I wrote the first version of this change asserting that granting `internal_auditor` `finance.access` was an
open question nobody had taken. It is not. `docs/Finance Module — Implementation Master Plan - v10.md:375`
records the decision, taken **2026-07-29** under a heading that literally reads `DECIDED`, three days before
the realignment this change corrects. That file is in the repository and I never opened it — I searched for
the *authority matrix* the two edited files cited, found it absent, reported that absence honestly, and
stopped there instead of asking what else in the repo spoke to the grant. The subagent review found it.

The corrected text is below. The general lesson is the one the method already states and I did not apply
hard enough: *absence of the document you were pointed at is not absence of a decision.*

## Headline

Done. Two files changed, comment and prose only, zero executable lines. `bin/quality` PASS (12/12, exit 0)
on the final tree. Reviewer findings 1, 2 and 5 accepted and fixed in this change on the project lead's
direction; findings 3 and 4 proper are out of scope and left for separate briefs.

**Review tier: targeted**, and provable rather than asserted — the seeder diff is 100% `//` lines and the
`'internal_auditor' => [` array with its three entries appears as unchanged context. Step 9 shows it.

## Revision after review

The first pass stopped at the brief's own stop clause (Part 1 item (c)) because the reason the brief told me
to write was false, reported that, and the lead directed a substitute. The subagent review then showed the
substitute was *also* false, in the opposite direction. The lead directed the final wording. Three states,
so a reader can see what moved:

| | Claim about the IA grant |
|---|---|
| **Original (pre-change)** | Blocked on safety — `finance.access` alone posts a payment. **False since `001fd1f`.** |
| **First pass** | An open decision nobody has taken. **False — v10:375 decided it 2026-07-29.** |
| **Shipped** | Decided and **UNIMPLEMENTED**. v10:375 says the auditor needs `finance.access`; `:379` makes the derivation a Phase 2 deliverable. |

Applied on the lead's direction:

1. **(c) rewritten** to *decided-but-unimplemented*, citing `v10:375` and `:379`, plus `:377` for why it is a
   Phase-2 deliverable rather than a one-line seeder edit: **there is not one `finance.*` read permission in
   the enum today**, so `finance.access` alone would buy entry to the surface with nothing financial to read.
2. **Every matrix citation dropped from the sections I own** — `IA=V on rows 3-6`, `rows 8/9, IA=D`, and the
   `V→(should-not-be-D)` framing. Discharges reviewer finding 2: nothing in my text now rests on a document
   that cannot be opened from this repository.
3. **The `(rows 8/9, IA=D — cross-school read/export)` endorsement dropped**, and `activity_log.view_cross_school`
   is nowhere re-asserted as correct. v10:375 says it **must not be granted**. Discharges the comment half of
   reviewer finding 4.
4. **Both stale citations corrected** (reviewer finding 5) — see "Proof / citations re-derived".

**Scope reading I made on item 2, flag it if wrong:** "drop the matrix citation entirely, both files" I read
as *within the sections this change owns*. `finance-seat-realignment.md` carries pre-existing matrix row
citations elsewhere (`:17-19` seat table, `:27-45` grant list, `:47-52` row 20) which I left untouched —
removing those would rewrite the whole document, which is not this change. If the intent was wider, it is a
one-pass follow-up.

## Deviations from the brief

**1. Part 1 item (c) — the brief's prescribed reason is false; I stopped rather than write it.** The brief
(lines 70-74) prescribed: *"`finance.access` is still an undifferentiated group gate covering read and
**non-payment write**."* Not true as of `001fd1f` — the enumeration is below. I stopped at the brief's own
stop clause (lines 85-89, 145) and reported before writing. Both substitute wordings came from the lead.

**2. The leak section — I added a framing line the brief did not ask for (3 lines, not 1).** The brief said
retitle, add *one line* naming `001fd1f`, do not delete. The preserved paragraph is **present tense**
(*"Exactly two mutating routes gate on `finance.access` alone… `finance_lead` and `accounts_supervisor` **can**
post payments"*). A retitle alone leaves a false present-tense assertion inside the document whose purpose in
this change is to stop making one. I put a three-line **Status: CLOSED** paragraph under the heading, before
the preserved body. **The original paragraph is preserved verbatim** — not a word deleted. Cut the framing
paragraph if that overshoots; the body is intact either way.

**3. Line range.** The brief called the leak section `:68-72`; it was `:68-75`. Lead confirmed. Now `:76-90`.

**General rule I formed, stated so it can be checked:** *every mutating route in `routes/endpoints/finance.php`
now carries its own `permission:` middleware, so bare `finance.access` confers read only.* Derived by
enumerating the file, not sampling. If it is wrong anywhere, both files are wrong with it. The reviewer
re-derived it independently and it held.

## Contradictions of the premise

**The brief's finding reproduced exactly.** `001fd1f` exists
(`feat(finance): finance.payment.record gates both payment doors (ADR 0048 D1)`).
`routes/endpoints/finance.php:24-25` and `:145-146` both carry `permission:finance.payment.record`. All three
stale sites present as described. The `:143` mis-citation is real — `:143` is the final line of the comment
block; the route is `:145`.

**What contradicted the brief was its prescribed fix.** Routes in the `finance.access` group carrying **no**
permission of their own:

| Line | Route | Verb |
|---|---|---|
| :71 | `students/{student}/invoices` | GET |
| :78 | `accounts` | GET |
| :88 | `fee-schedules` | GET |
| :89 | `fee-schedules/prefill` | GET |
| :117 | `discount-policies` | GET |
| :133 | `students/{student}/billable-enrollment` | GET |

All six GET. I checked each backing controller method for write-shaped calls
(`create|save|update|delete|insert|DB::|transaction|firstOrCreate|updateOr`) — none. Every mutating finance
route carries its own permission: `finance.invoice.generate`, `finance.payment.record`,
`finance.invoice.void-request.*`, `finance.credit-note.*`, `finance.fee-schedule.manage`,
`finance.fee-schedule.change.*`, `finance.discount-policy.change.*`. `finance.invoice.reduction.apply` is
body-checked inside the generate path, itself gated. `routes/web.php:146` adds two Inertia page shells;
`/finance/approvals` carries a derived checker gate. `grep` for `finance.access` in `app/` finds no policy or
Gate treating it as authority. `finance.payment.record` is granted to **`accounts_officer` alone** —
`database/seeders/RbacSeeder.php:340`.

**What contradicted my own first pass.** `docs/Finance Module — Implementation Master Plan - v10.md:375`,
under **DECIDED 2026-07-29**: *"`access` is a third class… the auditor needs the first [`finance.access`] and
must not have the fourth"*; `:379`: the derivation *"is a Phase 2 deliverable and is written separately"*;
`:377`: *"there is not one `finance.*` read permission in the enum today… Phase 2 owes a symmetry gate."*
Same paragraph `:375` also states `activity_log.view_cross_school` **"is read-shaped, is in scope, and must
not be granted"** — which `RbacSeeder.php:394` grants. That contradiction is real and out of scope here; see
findings.

**The brief's step-10 expectation is also wrong.** It predicted `PaymentRecordGateTest.php` would appear in
the grep. It does not, and should not: the test says *"finance.access ALONE **recorded** a payment"* (past
tense), matching none of the four patterns. Absence is correct, not a missed site. File untouched.

## What changed

| File | Lines | What |
|---|---|---|
| `database/seeders/RbacSeeder.php` | +23 −12 | Comment only. Role-list inline comment (`:84-88`) and the `internal_auditor` grants-map block comment (`:377-394`). |
| `docs/rbac/finance-seat-realignment.md` | +32 −15 | Prose only. IA deferral section (`:54-79`) and the leak section heading + status paragraph (`:81-90`). |

No array entry, enum value, grant or `grantsMap()` key touched. `rbac:sync` **not** run, in either form.

## Proof

### Step 8 — `git diff --stat`

```
database/seeders/RbacSeeder.php       | 35 +++++++++++++++++---------
 docs/rbac/finance-seat-realignment.md | 47 ++++++++++++++++++++++++-----------
 2 files changed, 55 insertions(+), 27 deletions(-)
```

Two files, as expected. All changed lines fall inside comment (`//`) and markdown regions.

### Step 9 — `git diff -- database/seeders/RbacSeeder.php` (the load-bearing proof)

```diff
@@ -81,9 +81,11 @@ class RbacSeeder extends Seeder
-        'internal_auditor',      // IA — activity-log only. NO finance.access: it alone records payments
-        // (endpoints/finance.php:24, :143), so the read-only seat cannot hold it
-        // until finance.payment.record splits payment authority off it.
+        'internal_auditor',      // IA — activity-log only. Still no finance.access, but the SAFETY reason is
+        // gone: 001fd1f gated both payment doors on finance.payment.record
+        // (endpoints/finance.php:24-25, :145-146), so finance.access now reaches
+        // GET reads only. The grant is DECIDED and UNIMPLEMENTED, not open — see
+        // the internal_auditor block in the grants map below.
         // NOTE: finance_void_approver (a one-sided void checker, seeded only so the access oracle
         // exercised the D1 single-side-checker case) was DELETED 2026-08-01 — Brookstone has no such
         // seat and it had zero holders in production. The D1 oracle row is a recorded coverage loss;
@@ -372,15 +374,24 @@ public static function grantsMap(): array
-            // Internal Auditor (IA) — new 2026-08-01, activity-log-only. NO finance.access, deliberately:
-            // finance.access is not a read-only gate — routes/endpoints/finance.php:24 and :143 (POST
-            // …/payments) carry finance.access and NO further permission, PaymentController calls no
-            // authorize(), and the payment FormRequests authorize()=true, so finance.access ALONE posts a
-            // payment. Granting it to the control role would let the auditor CREATE financial transactions —
-            // the exact V→(should-not-be-D) inversion the matrix forbids (IA=V on rows 3-6). IA ships as
-            // activity-log-only (matrix rows 8/9, IA=D — cross-school read/export). Its finance-screen READ
-            // access (rows 3-6, IA=V) is DEFERRED until finance.access splits read from act; recorded as a
-            // named deferral in docs/rbac/finance-seat-realignment.md, not an oversight.
+            // Internal Auditor (IA) — new 2026-08-01, activity-log-only. Still NO finance.access, but the
+            // ORIGINAL REASON NO LONGER HOLDS. It was: finance.access is not a read-only gate — both payment
+            // doors carried it with NO ability of their own, PaymentController calls no authorize() and both
+            // payment FormRequests authorize()=true, so finance.access ALONE posted a payment; granting it to
+            // the control role would have let the auditor CREATE financial transactions — the inversion a
+            // read-only control seat exists to prevent. 001fd1f (ADR 0048 D1) closed that: both doors now gate
+            // on finance.payment.record — routes/endpoints/finance.php:24-25 (invoice-addressed) and :145-146
+            // (student-addressed) — granted to accounts_officer alone (see AO above). Every other mutating
+            // finance route already carried its own permission, so finance.access today reaches only the six
+            // GET reads in that file plus the page shells, and confers NO payment capability on any holder.
+            // The grant is therefore UNIMPLEMENTED, not undecided — do not re-open it as a question. v10 §7.2
+            // (docs/Finance Module — Implementation Master Plan - v10.md:375, under DECIDED 2026-07-29) records
+            // that the auditor NEEDS finance.access; :379 makes it a Phase 2 deliverable. What :377 adds is
+            // why that is a deliverable and not a one-line edit here: NO finance.* read permission exists yet,
+            // so finance.access on its own would buy entry to the surface with nothing financial to read. The
+            // Phase 2 symmetry gate (every Finance resource with a write permission must carry a read one) is
+            // what makes the grant meaningful. IA ships activity-log-only until then;
+            // docs/rbac/finance-seat-realignment.md carries the same record.
             'internal_auditor' => [
                 PermissionEnum::ACTIVITY_LOG_VIEW->value,
                 PermissionEnum::ACTIVITY_LOG_EXPORT->value,
```

**`'internal_auditor' => [` and its three `PermissionEnum` entries appear as unchanged context lines.** Every
`+`/`-` line begins `//`. No array entry moved. This is what keeps the tier at targeted.

### Step 10 — grep for remaining sites

```
$ grep -rn "ALONE posts\|alone posts\|it alone records payments\|alone must not reach" \
    app database docs routes tests

docs/handoff/stale-payment-gate-claim-brief.md:1:# Brief — correct the stale "finance.access ALONE posts a payment" claim
docs/handoff/stale-payment-gate-claim-brief.md:25:   `// IA — activity-log only. NO finance.access: it alone records payments`
docs/handoff/stale-payment-gate-claim-brief.md:28:   both routes and therefore "ALONE posts a payment", and rests the entire
docs/handoff/stale-payment-gate-claim-brief.md:121:    grep -rn "ALONE posts\|alone posts\|it alone records payments\|alone must not reach" \
docs/handoff/stale-payment-gate-claim-brief.md:126:    it describes the design intent, that `finance.access` alone must not reach
routes/endpoints/finance.php:142: * so finance.access alone must not reach here. super_admin stays on both payment routes: record is
```

Both remaining classes correct: `routes/endpoints/finance.php:142` is present tense and correct (the design
intent `001fd1f` now enforces); the brief file is the brief itself, quoting the text it asked me to fix.
`PaymentRecordGateTest.php` absent for the tense reason above.

Wider sweep the brief did not ask for, for the same claim in other wording:

```
$ grep -rn --include='*.php' --include='*.md' -e "not a read-only gate" -e "splits payment authority" \
    -e "split read from act" -e "read vs act" app database docs routes tests | grep -v stale-payment-gate-claim-brief

database/seeders/RbacSeeder.php:378:            // ORIGINAL REASON NO LONGER HOLDS. It was: finance.access is not a read-only gate — both payment
docs/rbac/finance-seat-realignment.md:57:not act." That was wrong then: `finance.access` was not a read-only gate. Both payment doors carried it with
docs/rbac/finance-seat-realignment.md:90:for a dedicated `finance.payment.record` permission that splits payment authority off `finance.access`.
```

All three are my own text, framed as past tense / preserved history. No fourth site.

### Step 11 — tests

The `rtk` hook intercepts test-runner stdout and emits a JSON summary; I could not obtain human-readable Pest
output through `rtk proxy` or by invoking `vendor/pestphp/pest/bin/pest` directly. Summary line, then Pest's
own JUnit log, which the hook does not touch.

```
{"tool":"pest","result":"passed","tests":15,"passed":15,"assertions":24,"duration_ms":16830,"risky":1}
```

```
Tests\Feature\Finance\PaymentRecordGateTest: tests=4 failures=0 errors=0
    ok   it finance.access WITHOUT finance.payment.record is refused on BOTH payment routes (403)
    ok   it finance.access PLUS finance.payment.record records on BOTH payment routes (201)
    ok   it super_admin is not gate-blocked on either payment route (record is not a checker ability)
    ok   it a user with NO finance.access is refused on BOTH payment routes (outer group gate)
Tests\Feature\Rbac\FinanceRoleRealignmentTest: tests=4 failures=0 errors=0
    ok   it the guard REFUSES the half-done HoS set (approve added, submit still present)
    ok   it the REAL realigned HoS set (submit removed, approve only) passes
    ok   it NO seeded role holds both sides of any maker-checker pair
    ok   it the role set matches the realigned seats — old roles gone, new roles present
Tests\Feature\Rbac\GrantsMapSeparationTest: tests=2 failures=0 errors=0
    ok   it no role in grantsMap() grants both sides of any maker-checker pair (all pairs, not just enfo
    ok   it super_admin holds NO maker-checker ability in grantsMap() (ADR 0040)
Tests\Feature\Rbac\SeededPermissionCoverageTest: tests=5 failures=0 errors=0
    ok   it grants every seeded permission to at least one web-guard role
    ok   it keeps the exception list honest — every listed exception is truly role-less yet seeded
    ok   it never duplicates a (name, guard, team) role row
    ok   it never seeds maker and checker to the same role (ADR 0044 / ADR 0040 SoD)
    ok   it is non-destructive on re-run: runtime grant and revoke edits survive rbac sync
```

**There is no test named for `RbacSeeder`.** The brief said find it, not guess: the map is exercised by
`GrantsMapSeparationTest`, `SeededPermissionCoverageTest` and `FinanceRoleRealignmentTest` (the only test file
outside the fixtures naming `internal_auditor`). Ran all three plus `PaymentRecordGateTest`.

**Green here is uninformative, exactly as the brief predicted** — it proves the file still parses and the map
is unchanged, nothing more. The `risky: 1` is pre-existing; see findings.

### Step 12 — `bin/quality`, on the final tree

```
[10/12] architecture tests (§17.1)
   ✓ arch
[11/12] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[12/12] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
EXIT=0
```

All 12 steps green, exit 0. Run twice: once on the first pass, once on the revised tree. Both PASS.

### Step 7 (Part 2) — the re-derived count

Required re-derived or deleted. **Re-derived; it holds exactly**, against
`tests/fixtures/route-access-map.json`:

```
routes internal_auditor reaches: 38
of which /finance/*: 0
total routes in map: 350
```

The doc now states the date and the source, so the next reader knows what it was checked against.

### Citations re-derived (reviewer finding 5)

Both corrected against the **post-edit** tree, not carried:

- `finance.payment.record` grant — reported `:338`, actually **`:340`**. `:338` was the pre-edit line.
- The username in the staffing narrative — reviewer said `:117`, correct at review time; my subsequent doc
  edit shifted it to **`:122`**. Cited as `:122` below. Re-derive again if the doc moves.

## The watched red

**None available, and I did not manufacture one** — the brief said so (Part 3) and I agree: the change is
comment and prose only, and a comment cannot be regression-tested. Nothing I edited is reachable by any
assertion. **Treat every green in this report as evidence of "not broken", never of "correct".** The
correctness of this change rests on the enumeration above and on `v10:375`/`:377`/`:379`, both of which a
reviewer must read for themselves.

What partially stands in — and I found it already passing, I did not create it: `PaymentRecordGateTest` arm 1,
*"finance.access WITHOUT finance.payment.record is refused on BOTH payment routes (403)"*, is a pinned
bite-proof of the premise my text asserts. The reviewer additionally confirmed it is not a false green: arm 2
uses the same helper with one extra ability and asserts 201, so the helper does mint a permitted actor and
arm 1's 403 is the gate, not a broken fixture. I planted no regression and watched nothing go red.

## Database observations

**None.** No database work. The count re-derivation read the committed fixture
`tests/fixtures/route-access-map.json`, not a live database. `rbac:sync` not run in either form; no grant,
role row or assignment read or written. Tests ran against `portal_testing`, owned by `RefreshDatabase`. No
holder counts appear anywhere in this report because I derived none.

## Not done

- **No push.** Committed on the branch per direction; `main`/`staging` untouched.
- **Findings 3 and 4 proper left for separate briefs**, per the lead. Only the *comment half* of finding 4 is
  discharged here — this change no longer endorses `activity_log.view_cross_school`. The grant itself is
  still seeded at `RbacSeeder.php:394` and still contradicts `v10:375`.
- **I did not verify the authority matrix rows.** No longer load-bearing — every matrix citation is out of
  the sections I own. Pre-existing citations elsewhere in `finance-seat-realignment.md` remain unverified.
- **The three fixture oracles were not regenerated.** The diff is comments, so regeneration is a no-op. I did
  not prove that by running them.
- **Raw human-readable Pest output unavailable**, for the reason at step 11. Substituted the JUnit log rather
  than paraphrasing.
- **I did not evaluate whether IA should hold `finance.access`.** Out of scope. Neither file recommends it;
  both now point at the Phase 2 deliverable that owns it.

## Findings raised, not fixed

1. **`internal_auditor`'s three `activity_log.*` grants gate nothing — the "activity-log-only" seat reaches
   zero activity-log routes.** `routes/endpoints/activity-log.php` carries no permission middleware on any
   route and is required at `routes/api.php:256` **inside the `permission:academic_data.view` group**. IA
   holds no `academic_data.view`. Confirmed against the oracle: of the 38 routes IA reaches, **zero** are
   activity-log routes. The seat cannot do the one thing it exists to do. Severity: **fix**, contingent on
   holders — I derived none. `database/seeders/RbacSeeder.php:391`, `routes/api.php:256`.

2. **Two comments assert a gate that does not exist.** `routes/api.php:255` — *"gated per-endpoint by
   `activity_log.*` permissions"* — and `routes/web.php:239-240`, the same claim. No route in
   `routes/endpoints/activity-log.php` carries such middleware; `ActivityLogController` has exactly one
   authorization call, `$this->authorize('download', $export)` at `:283`. Same defect class this task exists
   to correct, and upstream of finding 1. Severity: **fix**. *(The `web.php` half is the reviewer's, not
   mine — I raised only `api.php` and was under-scoped.)*

3. **Two mutating routes sit inside a group whose comment calls it a read-only audit feed** —
   `POST /activity-logs/saved-filters` and `DELETE /activity-logs/saved-filters/{savedActivityFilter}`, gated
   only by the outer `permission:academic_data.view`. The oracle shows the DELETE reachable by five roles
   including `teacher`. Scoped to the caller's own saved filters. Severity: **ticket**. *(Reviewer's.)*

4. **`internal_auditor` holds a grant that removes the school filter, and `v10:375` says it must not be
   granted.** `RbacSeeder.php:394` grants `ACTIVITY_LOG_VIEW_CROSS_SCHOOL`;
   `app/Services/ActivityLog/ActivityLogQueryService.php:42` adds **no school predicate** when it is held.
   `v10:375`: *"is read-shaped, is in scope, and must not be granted… ADR 0036 makes isolation un-bypassable
   by role."* Inert today **only because of finding 1** — so the natural fix for finding 1 arms this in the
   same commit. **Findings 1 and 4 must be actioned together or not at all**, and finding 4 deserves its own
   full review first. Severity: **fix** as a standing item; deliberately not touched here.

5. **`SeededPermissionCoverageTest:50` is vacuous today.** `INTENTIONALLY_UNMAPPED = []` at `:25`, so the
   `foreach` at `:53` never executes: passes with zero assertions, reported risky. Not wrong by design — it
   guards the list once non-empty — but today it is a green that proves nothing. Severity: **ticket**.

6. **A production username in a committed doc.** `docs/rbac/finance-seat-realignment.md:122` names an
   individual account holder, against the ids-not-names privacy rule. Pre-existing, outside the sections I
   was asked to touch. Severity: **ticket**.

7. **Markdown lint warnings (MD060, table pipe spacing)** at `docs/rbac/finance-seat-realignment.md:16`,
   `:100`, `:109` — pre-existing tables, none in my edit. `bin/quality` does not fail on them.
   Severity: **ticket**.

## Note on tier, for whoever reviews this

Targeted is right by the brief's own test — step 9 proves no `grantsMap()` entry moved.

But that test measures the diff, not the consequence. What this change now *records* is that the IA
`finance.access` grant is decided and awaiting Phase 2. The next person to act on it will be making a grant
change, which is full-review tier, and finding 4 says it must not be made without resolving the cross-school
contradiction in the same pass. Nothing here recommends that grant. Flagging so the tier of this change is
not mistaken for the tier of what it points at.
