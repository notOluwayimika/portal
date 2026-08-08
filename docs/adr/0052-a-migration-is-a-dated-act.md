# 0052 — A migration is a dated act, not a live query

**Status:** Accepted — 2026-08. **Deciders:** owner + advisor. Converts four already-shipped
migrations and adds one repo-wide gate. Changes **no behaviour on any environment that has already
applied them**; changes what they do on **replay**, which is the point.

## Context

Four shipped convergence migrations computed their **target** from `RbacSeeder`'s grants map **at run
time**, while freezing their **governed role set** as a literal in the file. Two of them said so in
their docblocks and called it a design — *"The role SET is written out here (a migration is a fixed
historical act and must not re-shape itself if the map moves later); the GRANTS are derived."*

That sentence is the defect, written down and mistaken for a virtue. The two halves move in opposite
directions: the role set is pinned to the day the migration was written, the grants are pinned to
whatever the seeder map says the next time anyone runs `migrate`. A migration is a record of an act
that happened on a date. Reading a live source inside one means it is not a record of anything.

The 2026-08-04 seat move — every finance checker side moving from `head_of_school` and
`accounts_supervisor` to a new `executive_director` role — triggered both failure modes at once.

**Failure mode A — the migration changes identity.** `2026_08_05_100000_converge_finance_access_grants`
was authored to **grant** `finance.access` to `head_of_school`. Replayed against the post-seat-move
map, where HoS holds no finance grant at all, it **revokes** it. Same filename, same `migrations` row,
opposite act. Every replay path hits this: `migrate:fresh`, `migrate:refresh`, a restored backup, and
the release gate's own rollback-and-re-up.

**Failure mode B — the migration bricks.** Each one carried an "offender" pre-flight that ABORTED when
a global role *outside* its frozen governed set held a governed permission. `executive_director` holds
five of them by design. So on any seeded database `migrate` died — and it died earlier than the test
suite could show. Bite-proved before this change, against a seeded database:

```
>>> STEP 0 RESULT: RuntimeException
>>> MESSAGE: realign-finance-grants ABORTED: unexpected global role(s) grant the governed permissions:
    executive_director (holders=0). The maker source is not what the realignment assumed — investigate
    before widening this migration.
```

`2026_08_02_100000_realign_finance_governance_grants` is FIRST in filename order and **had no test file
at all** — nothing in `tests/` referenced it. The suite reported six red arms in two *other* files and
said nothing about the migration that actually stopped `migrate`. An unarmed migration is not a passing
migration; it is an unwatched one.

## Decision

> **A migration is a dated act, not a live query.** A migration that writes grants carries its target
> as a frozen literal, dated and attributed to the commit that added it. It never reads
> `RbacSeeder`'s grants map.

And the corollary, which is the part that is easy to get wrong:

> **A convergence migration aborts only on a condition its own writes would create. Every other
> surprise it reports and continues past.**

The only surviving abort is `2026_08_03`'s post-write, user-scoped duty-separation walk: that
migration's own grant is what puts a user on both sides of a maker–checker pair, so it throws and
rolls the transaction back. It is untouched by this ADR.

**And it is deliberately broader than the corollary, which is worth stating rather than glossing.**
The walk calls `DutySeparation::violations` over the user's *combined* roles and filters the result to
`enforcedPairs()` — the finance checkers — but never to the permissions this migration actually wrote
in this run. So a both-sides state assembled entirely from roles it does not govern would also throw.
That is a known overstatement of the rule above, ticketed rather than fixed: it cannot bite today, and
scoping the walk to this run's writes is a behaviour change that needs its own proof. The concrete
future case is on the `executive_director` branch — a user holding ED plus any `*.change.submit` maker
role is a violation this 2026-08-02 migration did not create and would roll back for.

Everything else — a permission row that no longer exists, a governed role that no longer exists, a
non-governed role that now holds the permission — is *the world moving on*. A migration cannot touch a
role it does not govern, so an "offender" is information, never danger. Aborting on it converts a
harmless surprise into a permanent brick on every future `migrate:fresh`.

Targets are frozen as **plain strings**, not `PermissionEnum::` constants. An enum case can be renamed
or deleted; a frozen historical act must not depend on today's enum any more than on today's map.
### The corollary is a two-part test, not a slogan

The corollary as first written — *"aborts only on a condition its own writes would create"* — could not
decide the next case that came to it, and a rule that cannot decide the next case is not yet a rule.
Before converting an abort to a report, ask two questions.

1. **Would continuing leave a hole this migration's own writes dug?** A migration whose act is a
   TRANSFER — strip one role, grant another — cannot half-apply. Skipping the grant half while the
   strip half runs is not the world moving on; it is the migration digging the hole itself.
2. **Does the abort message name a command that clears the condition and lets the migration pass?**

**Both yes → the abort stands.** It is a precondition, not a brick, and the operator has a one-command
exit.

**(1) no → report and continue, regardless of (2).** A migration cannot touch a role it does not
govern, so an offender is information.

**(1) yes and (2) no → do not convert and do not leave it.** The migration is unsafe to continue AND
unsafe to stop, which is a design problem, not a comment problem. Escalate it.

**Against the four files this branch converted, part 1 is NO for every converted abort.** Each
converges one role toward its own frozen slice, so a missing role or a missing permission costs
coverage, never coherence. That is why they became reports and skips, and why that stays correct.

**Against `2026_08_06_100000_move_head_of_school_finance_to_executive_director` — the next migration to
meet this rule — part 1 is YES.** Its act is a transfer: it strips five finance grants from
`head_of_school` and four from `accounts_supervisor` and grants nine to `executive_director`. Skipping
the grant half while the strip half runs leaves the four `*.change.approve/.reject` and the two
credit-note/void checker pairs held by **nobody** — and combined with the `Gate::before` maker–checker
exclusion (ADR 0040), no seat on the platform, `super_admin` included, could approve anything
financial. Part 2 is YES: `php artisan rbac:sync` creates the missing role row and the migration then
passes. **So it aborts, and its sibling abort on a missing target permission row aborts for the same
reason. Neither converts.**


### The same boundary, applied to `DutySeparation`

Freezing the target did not freeze the guard. The four converted migrations stopped reading the
seeder map for their **target** and went on reading live authority state for their **abort** — and a
2026-08-02 migration would still roll back for a both-sides state assembled entirely from roles it
does not govern. *(Found by the implementing agent after the freeze shipped; the advisor specified the
preservation of that abort and never looked inside it. This section exists because of that finding.)*

The boundary is **dated act vs runtime question**, not which primitive is called. `DutySeparation` has
three populations of caller, and only one of them needed narrowing.

Counts below are **executable call sites**, excluding `DutySeparation` itself and excluding mentions
in comments, re-derived with
`grep -rn "DutySeparation::" app database bin --include='*.php'` (20 in total, 2026-08-08). The
classification is the load-bearing part; the numbers are given so a reader can reproduce them rather
than trust them. **The first two counts were wrong when this section was written** — 7 and 4 — and are
corrected here; the classification they described was and is right.

**RUNTIME — 6 files, 11 call sites. Leave live.** `User::assignRole` (`app/Models/User.php:412`),
`SyncRolePermissionsRequest:112`, `RbacOverview:67,207`, `SchoolRbacOverview:95,308`,
`CheckStaffingReadiness:37,50,51`, `AuditDutySeparation:53,71`. Each asks a question about NOW: may
this assignment proceed, is this school staffed, who currently holds both sides. A live answer is the
only correct one, and freezing any of them would be this ADR's defect in reverse.

**DATED ACTS THAT REPORT — 5 migrations, 5 call sites. Leave live.** `DutySeparation::holdsViaGrant`
in the `report()` methods of `2026_08_02:252`, `2026_08_03:363`, `2026_08_04:222`, `2026_08_05:297`
and `2026_08_06:435`. (`2026_08_03` was omitted from the original list; it has a `report()` like the
rest.) A report of current state is exactly what they are; a frozen holder count would be a lie about
the database in front of you. Do not freeze these either.

**DATED ACTS THAT DECIDE — 2 walks, 4 call sites. Scoped.** The post-write walks in `2026_08_03`
(`:276` `enforcedPairs`, `:284` `violations`) and `2026_08_06` (`:325`, `:337`). These read live state
to decide whether to **roll back a dated act**, which is the one place the distinction bites. Both are
now scoped to what their own run WROTE:

- The filter is by PERMISSION, not by user. The walk still visits every user in every school; what
  narrowed is which findings it will roll back for. A pair is the migration's to block on only when at
  least one of its two sides is a permission **this run actually granted** — not the frozen target,
  the grants the diff wrote on this run.
- **Revocations are out of scope in both directions**: a revoke can only CLEAR a both-sides state,
  never create one. For `2026_08_06`, whose act is a transfer, that means the scope is the granted
  side only.
- A second, idempotent run grants nothing and therefore flags nothing — correct, because it wrote
  nothing.
- Violations outside that scope are **reported and continued past**: `user#<id> @ school#<id>`,
  counted, and the count repeated in the `AFTER` report, with the echo naming
  `php artisan finance:audit-duty-separation` as their owner. They are real and they matter; they are
  not a migration's to block on.

This answers part 1 of the two-part test rather than amending it. A violation the run did not create
is not a hole the run's own writes dug.

#### And say what the narrowing costs, because for `2026_08_06` it costs the whole abort

Narrowing to `$grantedThisRun` is right, and for `2026_08_06` it makes that walk's throw
**unreachable on every sequence `rbac:sync` produces**. That is not a defect of the narrowing; it is
what honesty about the narrowing looks like, and it must be written down or the abort reads as a
guarantee it does not give.

`executive_director` is new in `RbacSeeder::ROLES`, so `syncLogged` snapshots `$existingRoles`
(`RbacSeeder.php:492`) **before** creating it (`:507`) and ED takes the whole-slice `: $permissions`
branch (`:542-544`), receiving all nine. `TARGET['executive_director']` is those same nine, so its
grant diff is empty. `head_of_school`'s target is `[]`; `accounts_supervisor`'s is a subset of what it
holds. Every branch grants nothing, so `$grantedThisRun` is empty and **every** finding is out of
scope. The transfer's only real work is the revoke half — which is the entire reason that file exists
— and a revoke can never put a side into `$grantedThisRun`.

Measured on a production-shaped throwaway database (`rbac:sync`, then HoS and AS left holding their
pre-seat-move grants because `rbac:sync` revokes nothing, then one user holding `executive_director`
alongside `accounts_officer`): the walk found **eight** both-sides findings for that user — all four
ED pairs, both directions — reported every one as out of scope, and `migrate` exited 0.

Two consequences worth stating rather than leaving to be rediscovered:

1. **A test that reaches such a throw proves the branch EXECUTES, not that it GUARDS.** Say so in the
   test, or the next reader counts it as coverage. What covers the ED direction is
   `DutySeparation::assertAssignmentAllowed` at grant time (`app/Models/User.php:412`), with
   `finance:audit-duty-separation` as the detector for pairings that predate it.
2. **Keep the throw anyway.** It costs nothing and guards a path nobody has enumerated. Deleting an
   abort because today's sequences cannot reach it is how the next sequence gets no guard at all.

`2026_08_03`'s walk is different and is **not** covered by this: its act is an ADD, its target grants
three maker sides, and its `$grantedThisRun` is non-empty whenever there is drift to converge — which
is the case the replay in the carve-out below exercises.

### Corollary: an applied migration must not be edited

The decision above rules that a migration is a **dated act** rather than a live query. The same
principle forbids editing one that has already run, and this is the form the rule takes in day-to-day
work rather than in a convergence migration: once `up()` has executed anywhere, the file and the
database have **diverged**. `down()` now describes a shape the applied `up()` never created, and the
pairing that makes a rollback meaningful is silently broken.

**Observed on 2026-08-08**, amending `2026_08_08_100000_realign_opening_balance_staging_for_per_fee_type_file`
after it had already been applied to two local databases. The amended `down()` tried to drop a column
its applied `up()` had never added — MySQL 1091 — **after** it had already re-added an old unique key.
Both databases were left half-rolled-back: old key present, three column pairs and eight CHECK
constraints missing. `migrate` then reported **"Nothing to migrate"**, because the `migrations` table
records **that a version ran, not what it did**. Nothing in the tooling reports the divergence; a bare
exit code from `migrate:rollback` cannot see it. Only reading the resulting schema found it.

**The rule.** To change a migration that has run **anywhere** — including only your own machine:

1. **Roll it back FIRST**, then edit, then re-apply. Or ship a **new dated migration** and leave the
   applied one alone. Those are the only two options.
2. **If a rollback aborts partway, stop.** The database is now in a state that neither `up()` nor
   `down()` describes, and no further migration command will do the right thing. Repair it **by hand**
   against the recorded pre-migration shape before anything else runs.
3. **Verify by SHAPE afterwards**, from `information_schema` — columns, indexes, constraints — not by
   the exit code and not by the `migrations` table. Both of those report success over exactly this
   failure.

This is the same class as the `--step=N` audit error already recorded in `docs/testing.md`: a
migration command that exits 0 having done something other than what you assumed, with nothing in the
output to say so.

#### The carve-out: `2026_08_03`, edited after it had already applied

`feat/executive-director-role`'s commit `17da5c3` edits the executing half of
`2026_08_03_100000_converge_finance_change_grants`, a migration applied long before this corollary
existed. It narrows the post-write duty-separation walk's abort predicate — precisely the thing the
rule above forbids, because replay now does something the original run did not.

**The edit stands.** Not by seniority and not by analogy: by four conditions, each proved on a
throwaway database rather than argued.

**1. Neither sanctioned exit exists for this file.** The rule offers two: roll back first, or ship a
new dated migration. `2026_08_03`'s `down()` is a deliberate, documented no-op, so rolling it back
restores nothing and re-applying it is simply a replay — the exit is a tautology here. And no new
dated migration can change an earlier migration's abort predicate; nothing a later file writes stops
`2026_08_03::up()` throwing on the next replay. The narrowing is not portable to another file, which
is what makes this a carve-out rather than a shortcut.

**2. Left alone the file is unreplayable.** Replayed on a from-zero throwaway database seeded by
`rbac:sync` at today's map, with one user holding `executive_director` alongside a role granting only
`finance.credit-note.submit`, and `2026_08_03`'s `migrations` row cleared:

```
converge-finance-change-grants ABORTED (rolled back): 2 user(s) would hold both sides of a finance
maker-checker pair after convergence — user#2 @ school#1 finance.credit-note.submit<>finance.credit-
note.approve; user#2 @ school#1 finance.credit-note.submit<>finance.credit-note.reject.
```

`migrate` exited 1 and the `migrations` row was never written — so the ADD-side gap this migration
exists to close stays open, and no `migrate` command can close it. Read the pair it aborted over:
`finance.credit-note.*` is in **neither** of the two namespaces this migration governs, and the state
was assembled from two roles it cannot touch. The narrowed file, same database, same planted state,
reports both findings, names `finance:audit-duty-separation` as their owner and **commits** — exit 0,
the three maker grants landed. Replaying `2026_08_03` and `2026_08_06` together in filename order
ends with `head_of_school` holding zero `finance.*` grants and `accounts_officer` holding its maker
side. The dated act, reproduced.

The narrowing therefore does not rewrite the act. It is the only version of the file that can still
perform it.

**3. It is behaviour-identical on the state the original run met.** The out-of-scope set was empty on
2026-08-02 — it had to be, or that run would have aborted instead of committing. With that set empty
the two versions are the same program: same diff, same writes, same activity rows, same `AFTER`
counts, differing only by an `out-of-scope both-sides findings=0` field in one echo. This is the same
property that made the four target freezes behaviour-preserving, and it is why the divergence this
corollary was written from — an applied `up()` and a `down()` describing different shapes — cannot
arise here.

**4. The pure from-zero path decides nothing, and it is the first thing anyone will try.** On
`migrate` against an empty database the walk is **unreachable**; the fresh-install guard keyed on the
permission substrate returns first, identically on both versions:

```
2026_08_03_100000_converge_finance_change_grants   converge-finance-change-grants: finance RBAC
substrate unseeded (no finance-change permissions) — nothing to converge.
```

A from-zero replay that does not abort is therefore not evidence the abort is harmless. The proof
above seeds the substrate before clearing the `migrations` row, which is what makes the walk
reachable at all.

**The scope of the carve-out.** An applied migration may be edited only when **all four** hold: its
`down()` is a documented no-op, so no `up()`/`down()` shape divergence is possible; the edit is
provably behaviour-identical on the state the original run met; the file is otherwise unreplayable;
and no new dated migration could carry the change. The replay evidence goes in the branch's
implementation report, raw. Anything failing one of the four goes back to the two options above.

#### What the corollary governs is the EXECUTING half — a comment is not in scope

The rule above says "an applied migration must not be edited", and read literally that forbids fixing
a comment that has gone false — which would mean a file's prose can only ever get more wrong. It was
never the intent, and the practice already says so: `2026_08_08_100000`'s retraction box was added
after that migration had applied to two databases, amending the docblock and leaving `up()` and
`down()` untouched.

**So: the corollary governs `up()` and `down()`. Comments may be corrected at any time**, on three
conditions.

1. **Retract, do not rewrite.** Strike the superseded text so it stays readable, and box what changed
   and when. The struck sentences are the reasoning the next author would otherwise reconstruct from
   scratch, and a silently-edited comment teaches nobody why it went. `2026_08_08_100000:13-35` is the
   form.
2. **Prove the executing half is untouched, and paste the proof.** For a docblock above the class,
   `diff <(git show <ref>:<file> | sed -n '/^return new class/,$p') <(sed -n '/^return new class/,$p' <file>)`.
   That slice does **not** work when the amended comment is inside the class body — there, strip
   comments from both revisions with `token_get_all` and diff the remainder.
3. **Narrow the claim to match.** "Executing half byte-identical to `<ref>`", never "byte-identical".
   The wider claim is the one that stops being true the moment you touch a comment, and it was never
   the claim that mattered.

`2026_08_06_100000_move_head_of_school_finance_to_executive_director`'s post-write walk comment was
corrected this way on 2026-08-08 (see the section above on what the narrowing costs). It needs no
carve-out on the four conditions for a second reason: it has **never applied to an environment that
persists** — it is on an unmerged branch, and every run was against a throwaway replay database
created and dropped in the same session. The corollary exists because a file and a **live** database
diverge; there is no such database here.

### A FORCING target freezes a namespace, not a row set

Added 2026-08-09, from §9 step 4c. This is the corollary's other edge, and it bites in the opposite
direction to everything above: not "an old migration gets rewritten by a later map edit", but **an old
migration keeps acting on rows that did not exist when it was written**.

A convergence migration whose target is **forcing** — the governed role's slice is made to *equal* the
frozen literal, not to *contain* it — is scoped by a **namespace prefix**, and a namespace has no
expiry. `2026_08_06_100000_move_head_of_school_finance_to_executive_director` governs `finance.` on
three roles. Every `finance.*` ability granted to one of those roles in any **later** commit is
therefore revoked by that file on every environment where it has not yet run, silently, whatever
`RbacSeeder::grantsMap()` says at the time.

`rbac:sync` does not repair it. By the time the migration runs, the permission is no longer new, and
`RbacSeeder::sync()` grants an existing role only permissions created in that same run — so the
deploy order (`rbac:sync`, then `migrate`) writes the grant and then takes it away, with nothing
downstream to notice.

**Measured, not reasoned.** §9 step 4c added `finance.opening-balance.submit/.approve/.reject` to the
map. On `portal_testing`: seed → `executive_director` holds `.approve` + `.reject`,
`accounts_supervisor` holds `.submit`; run `2026_08_06_100000` → all three gone.
`accounts_officer`'s `.submit` survived only because that role is not governed by that TARGET.

**And it is a rule, not a note.** `tests/Feature/Rbac/ForcingMigrationsDoNotStripLaterGrantsTest.php`
enforces it repo-wide: for every role a forcing migration governs, every permission the grants map
gives that role inside the frozen namespace must appear either in the migration's own `TARGET` or in
an `@converges <role> <permission>` marker on a migration dated after it. Both sides are derived from
source — the namespace and target by reflecting the migration's constants, the grants from
`RbacSeeder`, the markers by scanning `database/migrations/` — so a grant added tomorrow with no
convergence migration turns it red with nobody editing the test. **Forcing migrations are registered
by filename in that file's `FORCING_MIGRATIONS` list**, because "forcing" is a property of the body
(it revokes `array_diff($current, $target)`) that no constant declares; today the list has one entry.

**The rule.** Adding a `finance.*` grant to a role governed by a forcing target requires a **new dated
convergence migration**, additive-only, dated after the forcing one. Never edit the forcing literal:
its frozen act is honest and describes what its author intended on the day, which is the whole value
of freezing it. 4c's repair is `2026_08_09_110000_converge_opening_balance_grants.php`.

**And the distinction that produced the mistake, because it is the reusable part.**
`bin/ci-grants-convergence-lint.php`'s exemption 1 says a **new** permission needs no convergence
migration. That is true, and it is a statement about **the lint**: a new permission lands in
`$newPermissions`, so `rbac:sync` grants it everywhere and there is no drift to catch. It says nothing
about whether the grant **survives a deploy**. Two different questions; the first does not answer the
second. The same note now sits beside exemption 1 in the lint itself.

### A REPORT is a dated act too — and it is the one artifact that must NOT be corrected

Added 2026-08-08, from `fix/pest-not-discards-messages`. This ADR's title names the general principle
and its body has so far only ever applied it to migrations. The principle is wider, and the second
instance is worth writing down because the correct handling is the **opposite** of everything else in
`docs/`.

**The boundary.** Two kinds of prose live in this repository and they are governed by opposite rules.

| | Examples | Rule |
|---|---|---|
| **Live documentation** | `docs/handoff/finance-mvp-cut-brief.md`, route-block docblocks, class docblocks, these ADRs | **Correct it.** A sentence that has gone false is read as a statement about what the system does today, and a stale one is worse than none — it is a wrong answer with the authority of a written one. |
| **Dated records** | `docs/handoff/reports/*.md` — implementation reports, review findings, replay evidence | **Do not correct it.** Amend by appending or by a later report; never by editing the claim. |

**Why a report is the exception, stated as the reasoning rather than the rule.** A report's entire
value is that it says *what was true at the moment it was written, and what the author believed then*.
Editing it to match today's tree destroys the only thing it is for. Worse, it destroys it invisibly:
the corrected report still reads as a contemporaneous record, so a reader cannot tell that the claim
they are trusting was written after the fact and against a different tree.

The case that produced this: §9 step 5b-ii's docs sweep found five sentences asserting that
opening-balance batches had no decision surface. Four were in
`docs/handoff/reports/fix-finance-approvals-queue-renders-every-type.md` — 5a's own implementation
report — and were **left untouched**; the fifth was in the MVP cut brief, a live status document, and
was corrected. Both halves of that call are this rule.

**This is the same shape as the `up()`/`down()` versus comment boundary above, one level up.** There,
the executing half of an applied migration is frozen and its comments may be corrected. Here, the
record is frozen and the live documentation must be corrected. In both cases the question is not "is
this file allowed to change" but "does this text make a claim about a MOMENT, or about the SYSTEM" —
and the answer decides the rule.

**Why this is not its own ADR.** The principle is already stated in this document's title; a second
ADR would be a second place to keep one statement in step, which is the failure mode this ADR exists
to object to. It is filed here as an extension, clearly dated, rather than as a rewrite of the
migration sections, which continue to say exactly what they said.

**What it does not license.** A report that was WRONG WHEN WRITTEN is not corrected either — it is
answered, in the same file if the branch is still open (an appended section, dated) or in the next
report if it is not. The distinction is between amending a record and forging one.

**There is no gate behind this**, and by this repository's own standard that makes it a convention
rather than a rule (`bin/quality` cannot tell a report from a brief, and a lint keyed on the
`docs/handoff/reports/` path would fire on the legitimate append). Recorded as a convention, honestly
labelled, rather than dressed as a control.

## The trade, stated rather than buried

This is the honest cost and it is not hypothetical. An environment that genuinely has **not** run
`rbac:sync` will now get a loud `SKIPPED:` line where it used to get a hard stop, and an operator who
does not read it will under-converge — the migration will have run, the `migrations` row will say so,
and some grants will not be where the map says they are. `php artisan rbac:diff-grants` is the thing
that finds that afterwards.

The trade was taken deliberately: **a stop that fires correctly once and incorrectly forever is worse
than a report that has to be read.** The abort fires on a real problem roughly never and on the world
having moved on every single time, and its cost when wrong is that nobody can migrate at all.

## Consequences

Four files converted, each frozen at the commit that added it:

| file | what changed | frozen at | date |
| --- | --- | --- | --- |
| `2026_08_02_100000_realign_finance_governance_grants.php` | target frozen; three aborts → report/skip | `f143b40` | 2026-08-01 |
| `2026_08_03_100000_converge_finance_change_grants.php` | target frozen; three aborts → report/skip (the duty-separation walk keeps its throw) | `01fdeda` | 2026-08-02 |
| `2026_08_04_100000_revoke_internal_auditor_cross_school.php` | **already frozen** — its act is `PERMISSION` + `ROLE`, literals before this branch. Its live-map assertion deleted; three aborts → report/skip | — | 2026-08-02 |
| `2026_08_05_100000_converge_finance_access_grants.php` | target frozen; three aborts → report/skip | `af9db7a` | 2026-08-03 |

`2026_08_04` gets no `TARGET` const, deliberately: it revokes one named permission from one named
role, both already literals, so a const no code reads would assert a wiring that does not exist. An
ADR that overstates its own coverage is the same failure as a green test that scanned zero files.

The three adding commits agree with their frozen literals on the relevant map slices, which is what
makes this edit behaviour-preserving on every environment that has already applied them.

`2026_08_06_100000_move_head_of_school_finance_to_executive_director.php` carries the identical defect
and is converted on its own branch, after this merges. The gate below forces it.

**The gate.** `tests/Feature/Rbac/MigrationsDoNotReadTheSeederMapTest.php` globs every migration and
asserts none contains `grantsMap`. It asserts the glob matched more than 100 files **first**: a scan of
zero files finds no offender and reports green, and that is the one failure mode this test may not
have.

It deliberately does **not** live in `bin/ci-grants-convergence-lint.php` (`bin/quality` step 7). That
gate is diff-based and reads only the files a branch ADDS, by a rule that is correct for what it does —
so a migration already on the base is structurally invisible to it, and files already on the base are
exactly the population this invariant governs.

**Remainder, ticketed not worked.** Re-derived on this branch, not carried:

```
$ grep -rhoE "RbacSeeder::[A-Za-z_]+|PermissionEnum::[A-Za-z_]+" database/migrations/ | sort | uniq -c | sort -rn
  23 RbacSeeder::GUARD
   5 RbacSeeder::sync
   2 PermissionEnum::ISOLATION_CROSSING
   1 RbacSeeder::syncLogged
   1 RbacSeeder::SUPER_ADMIN_PLATFORM
```

**All five `RbacSeeder::sync` occurrences are PROSE IN DOCBLOCKS**, not calls — `2026_08_02:17`,
`:42`, `2026_08_03:16`, `2026_08_04:48`, `2026_08_05:16`, each explaining why non-destructive sync
made the migration necessary. So does the single `syncLogged` (`2026_08_04:30`). `RbacSeeder::GUARD`
is a guard-name constant, not a decision that moves. There is no `::ROLES` and no
`PermissionEnum::FINANCE_ACCESS` in `database/migrations/` on this branch at all.

An earlier draft of this ADR said *"`RbacSeeder::sync` in a migration is the extreme form of the same
defect — a migration that re-runs the seeder re-shapes itself completely."* That sentence described
**zero lines of executable code**, and it is withdrawn. The claim was asserted from a grep whose hits
were never read, on a different branch, and carried forward — which is the same failure this ADR is
written against, committed inside it.

**The class it described is real, and there is one live instance.**
`database/migrations/2026_05_06_085734_update_terms_and_curricula_tables.php:48`:

```php
Artisan::call('db:seed', ['--class' => 'TermSeeder', '--force' => true]);
```

A migration that re-runs a seeder inside `up()`. It is invisible to the gate below, because it
carries no `RbacSeeder::` token at all — the gate scans for one string and this instance does not
contain it. The file documents itself honestly and at length (`:27-48`): `TermSeeder` computes every
term window from `now()->startOfYear()`, so *"the rows this migration writes DEPEND ON THE DAY IT
RUNS"*, and its `updateOrCreate` re-run *"OVERWRITES the dates of terms that already exist"*. Its own
paragraph records **NOT REPAIRED, DELIBERATELY** — it has already run everywhere, and rewriting it
would change only what a future `migrate:fresh` produces. The same comment records the cost: term
dates are load-bearing for money through the `finance_fee_schedules.term_id` RESTRICT FK.

That decision stands and this branch does not disturb it. It is named here so the class has a real
address instead of a wrong one, and ticketed: *stop seeding from a migration at all*, which the file
itself names as the correct fix and as a separate change with its own data question.

The line for THIS gate is drawn at the grants map for one reason: it is the only source whose value is
a **business decision that moves every time Brookstone changes their mind**, and the only one that has
actually bitten. Widening the gate — to seeder invocation, to `Artisan::call`, to the enum — is a
separate decision with a separate blast radius, and the census above is what it should be sized
against.
