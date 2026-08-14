# U1 — the fee schedules page (commits 1 and 2)

**Scope of this file.** The blocks below are what was asked of the implementing side for U1,
reproduced verbatim in the order they arrived. Blocks 1–4 are **commit 1** (the data surface) and all
four arrived on **2026-08-11**. Block 5 is **commit 2** (the route, the page and the sidebar entry)
and arrived on **2026-08-11**.

**This header used to say commit 2 was "NOT yet written and NOT covered by this file".** That was
true when blocks 1–4 were the whole file and stopped being true the moment block 5 was appended;
corrected here by the commit that appended it. Commit 1 still deliberately ships no route, no `.tsx`
and no sidebar entry — block 1 says so explicitly and blocks 2–4 work within that boundary. Commit 2
is where all three arrive.

**Why verbatim.** A reviewer's first attack is whether a faithful implementation was built on a false
premise — the one failure that passes every other check. That attack needs the text that was asked
for, not a summary of it. Nothing below is paraphrased, tidied or merged into a narrative. The blocks
are fenced so their indentation, backticks and line breaks survive rendering; the fences are the only
thing added.

**Block 5's `DRIVE IT` section has been superseded, and is deliberately left standing.** The browser
drive procedure it spells out — seed the fixture, sign in as these seats, report what the selects
contained — is now the `finance-drive` skill (`.claude/skills/finance-drive/SKILL.md`), and a brief
written today asks for a drive in three lines and points there. That section is **not** edited to
match, because this file's contract is verbatim: a reviewer attacking whether commit 2 was built on
a false premise needs the text as it arrived, not the text as it would be written now. Read it as
the record of what was asked, and the skill as what to do.

**The one substantive difference, named so nobody has to diff for it.** Line 909 asks for the
selects *"by count and by label"*. The skill's rule is by count and by **value**, because both drive
schools are seeded with identical labels by construction — `First Term`, `JSS 1`, `JSS 2` —
so a label comparison across two seats proves nothing about isolation. The report answering this
block did it by value and said so, which is where the rule came from; the instruction it answered
is the one still standing above. On this point the brief is wrong and the skill is right.

The implementation reports that answer these blocks are
[`reports/feat-fee-schedules-data-surface.md`](reports/feat-fee-schedules-data-surface.md)
(commit 1, blocks 1–4) and
[`reports/feat-fee-schedules-screen.md`](reports/feat-fee-schedules-screen.md)
(commit 2, block 5).

---

## Block 1 — the commit-1 brief (2026-08-11)

```text
CONTEXT

Branch off `staging` AFTER PRs #234 and #235 are merged. Confirm before starting:

  git fetch origin && git log --oneline origin/staging -5

`origin/staging` must contain both merges. If it does not, stop and say so — this commit
reads FeeScheduleController::editDraft and EditFeeScheduleDraft, which arrived in #234.

  git switch -c feat/fee-schedules-data-surface origin/staging

This is COMMIT 1 OF 2 of U1 (the fee schedules page). Commit 1 is the DATA SURFACE only:
no route, no .tsx, no sidebar entry. Commit 2 is the screen and comes in a separate block
after this one's report. Do not build any part of commit 2 here.


WHY THIS COMMIT EXISTS

A fee schedule can today only be authored with curl. Every endpoint is built — index,
prefill, store, supersede, editDraft, and the publish/retire change flow — and there is no
screen. Commit 2 builds the screen. It cannot be built against the current data surface,
because three things about that surface make an author-and-edit page impossible rather than
merely awkward. This commit fixes those three, with tests, so commit 2 is only a screen.


1. SPLIT THE REQUEST — EditFeeScheduleDraftRequest

`PUT /v1/finance/fee-schedules/{feeSchedule:uuid}/draft` is validated by FeeScheduleRequest,
which requires `term_id` and `class_level_id`. `EditFeeScheduleDraft::handle(FeeSchedule,
string $label, array $items)` never receives them. So the endpoint demands two fields, refuses
the request without them, and then discards them. A page editing a draft would have to send a
term and a class level it is not changing and that nothing reads.

This is the decision recorded in docs/handoff/tickets/edit-draft-request-reuse-decide-at-u1.md,
and the decision is: SPLIT THE REQUEST.

  - Add app/Finance/Http/Requests/EditFeeScheduleDraftRequest.php.
  - It carries `label` and the `items.*` rules ONLY. Copy them from FeeScheduleRequest
    unchanged, INCLUDING `items.*.bank_account_id`'s scoped Rule::exists (school_id =
    ActiveSchool::id(), deactivated_at null) and `items.*.currency`'s ISO-4217 regex.
  - It carries `itemSpecs()`, same body.
  - It does NOT carry `term_id` or `class_level_id`.
  - Point FeeScheduleController::editDraft at it. FeeScheduleRequest keeps `store` and
    `supersede`, which DO read both fields.

DO NOT close this the other way — do not make EditFeeScheduleDraft consume term_id and
class_level_id. Moving a draft to a different term or class level is a different act with a
different uniqueness collision (finance_fee_schedules_pending_unique), and letting an edit
do it silently is a bigger change than the one being asked for.

The ticket file is CLOSED BY THIS COMMIT — delete docs/handoff/tickets/edit-draft-request-reuse-decide-at-u1.md
and say so in the commit message.

WATCHED RED: a `/draft` request carrying label + items and NO term_id must succeed. Watch it
fail by pointing the route back at FeeScheduleRequest, then restore.


2. THE RESOURCE CANNOT ROUND-TRIP AN EDIT

app/Finance/Http/Resources/FeeScheduleResource.php serialises each item as
{id, description, amount, is_mandatory, is_discountable, sort_order}. `bank_account_id` is
absent — and after change 1 above, EditFeeScheduleDraftRequest REQUIRES it on every line.

So an operator opening a draft to fix one typo in one description would have to re-pick the
destination bank account for every line on the schedule, from nothing, because the screen was
never told what those lines currently point at. Pick wrong and money lands in the wrong account.

  - Add `bank_account_id` to each item in FeeScheduleResource: the BankAccount's **uuid**, not
    its integer id. The uuid is the wire form everywhere else (the exists rule keys on uuid,
    EditFeeScheduleDraft resolves uuid -> id).
  - `index()` currently eager-loads `items` only. Add `items.bankAccount` — without it this is
    one query per item across every schedule on the page. If FeeItem has no `bankAccount`
    relation, add it (BelongsTo BankAccount); check first and report which it was.

Separately, the resource returns `term_id` and `class_level_id` as raw integers. A list of
schedules would read "Term 7 / Class level 12".

  - Add `term_label` and `class_level_label`, BOTH THROUGH whenLoaded — `prefill()` calls
    `new FeeScheduleResource($schedule->loadMissing('items'))` with no term loaded, and an
    unconditional accessor would lazy-load a term and a session on the billing read path.
  - term_label: `trim(($term->academicSession->name ?? '').' — '.$term->name)`. This is the
    same string routes/web.php builds for the opening-balance operator screen's term select —
    two screens naming the same term differently is how an operator picks the wrong one.
  - class_level_label: the ClassLevel's `name`.
  - `index()` eager-loads `term.academicSession` and `classLevel`.

WATCHED RED: an arm asserting `items.0.bank_account_id` equals the account's uuid, watched
failing with the field removed. An arm asserting term_label and class_level_label on index,
and an arm asserting prefill's payload does NOT change shape (prefill returns `schedule` and
`lines`; whenLoaded must leave the labels absent there rather than present-and-null).


3. INDEX RETURNS EVERY SCHEDULE EVER WRITTEN

`FeeScheduleController::index()` has no filter, no pagination, orders by id desc and loads
every item of every schedule. In September that is a handful of rows. In year three it is
every term of every year for every class level of two schools, with their items, in one
response, so a screen can show one term.

Add TWO optional query filters and nothing else:

  - `term_id`   — integer, optional, `Rule::exists('terms','id')->where('school_id', ActiveSchool::id())`
  - `status`    — optional, `Rule::enum(FeeScheduleStatus::class)`

Applied with `->when()`. Absent means unfiltered — the current behaviour, so nothing that
calls index today changes.

PAGINATION IS NOT IN SCOPE. File it: docs/handoff/tickets/fee-schedule-index-unpaginated.md —
"index() returns every schedule for the school with its items; the term filter bounds it for
the screen but a caller passing no term still gets everything. Revisit when a school has more
than one year of schedules."

WATCHED RED: an arm passing a term_id that belongs to ANOTHER school must 422, not return that
school's rows — watched failing with the `->where('school_id', ...)` removed.


4. THE DRIVE FIXTURE SEEDS NO ACADEMIC SLOT

Verified, not assumed: database/seeders/DriveCastSeeder.php contains no Term, no ClassLevel and
no AcademicSession. Enrollments are built as StudentCurriculum rows against a Curriculum
factory. app/Finance/Console/DriveFinanceStates.php touches none of the three either.

So a browser drive of commit 2's screen would land on an empty term select and an empty class
level select and be able to create nothing — the exact failure that hit the opening-balance
operator screen (routes/web.php's comment on `->id` vs the model), except caused by the fixture
rather than by the query. This is a PRECONDITION of commit 2's drive, being fixed now so commit 2
does not discover it.

Per drive school (A and B), seed in DriveCastSeeder:
  - one AcademicSession
  - one Term inside it
  - at least TWO ClassLevels (one is enough to render a select and not enough to prove it lists)

Then, in the same command that reports the fixture, print the counts — terms, class levels,
sessions, per school — so the next drive reads them instead of trusting this paragraph.

Report the exact required columns you found on each of the three tables. Do not infer them from
the models' $fillable; read the migrations or information_schema and paste what you read.


WHAT THIS COMMIT DOES NOT TOUCH

  - routes/web.php — no page route. Commit 2.
  - resources/js/** — no .tsx, no sidebar. Commit 2.
  - tests/fixtures/route-access-map.json — no new route, so no regeneration. If you find
    yourself regenerating it, you have built part of commit 2; stop.
  - app/Enums/Permission.php — no new permission is coined. finance.fee-schedule.manage and
    finance.fee-schedule.change.submit already exist and already gate the endpoints.
  - Any Action. EditFeeScheduleDraft, CreateFeeSchedule and SubmitFeeScheduleChange are
    correct as they stand; this commit changes what reaches them, not what they do.


GATE

  bin/quality

Raw, all 14 steps, output pasted unedited. If a step fails on a file you did not touch, say
so and paste it anyway — the gate has been observed nondeterministic (ADR 0053, fifth residual),
and a re-run that passes is reported as two runs, not as one pass.


REPORT

Facts, evidence, deviations, and what you could not verify. Command output pasted raw and
unedited; if long, cut whole lines from the middle and mark the cut — never re-render it.

No severity calls on your own work. Do not nominate what you think is contentious. Do not
reference material I do not have.

For each of the four changes: what the code did before (quoted, with file:line read from the
tree, not recalled), what it does now, and the arm that would catch a regression — each arm
named with the red you watched it produce.
```

---

## Block 2 — the remediation of the first cold review (2026-08-11)

```text
REMEDIATION — U1 commit 1, feat/fee-schedules-data-surface

The cold review's five findings all reproduce against 48279f5. I verified each one myself
against the tree; I am not taking them on the reviewer's word and neither should you.

Three severity calls move. Findings 2, 3 and 5 are FIXES here, not tickets. Reasons are
under each. Finding 4 stays a ticket.

`48279f5` is unpushed. Amend into it if that is still true — the report commit `01c328a`
then needs the correction note below anyway, so it is being rewritten regardless. If you
have pushed, two commits, and say so.


FIX 1 — THE DRIVE FIXTURE CANNOT AUTHOR IN SCHOOL B

Confirmed independently: `DriveFinanceStates::bankAccountId()` (:44) is called from :62, :69
and :84 only, all three inside RecordPayment paths. `SeedDriveFixture.php:88` gives School B
exactly one state, `plainInvoice`, which is `$this->invoice(...)` and records no payment. So
`finance_bank_accounts` is empty for school B. `DriveCastSeeder.php:157` gives
school-b@drive.test the `accounts_officer` role, which holds `finance.fee-schedule.manage`
(RbacSeeder.php:381), and `HasFeeScheduleItemRules` makes `items.*.bank_account_id` required,
School-scoped and not-deactivated.

Result: commit 2's drive signs in as the isolation seat, opens the author screen, and the
bank-account picker is empty. Same failure `seedAcademicSlot()` was written to prevent, one
field over — and the count table's own comment ("Zero in any column means the screen cannot
author anything") prints three columns, not the fourth that governs the same outcome.

DO NOT CLOSE IT BY CREATING A BankAccount IN DriveCastSeeder. `bankAccountId()` is a
firstOrCreate keyed on account_number `'90'.str_pad($schoolId, 8, '0', STR_PAD_LEFT)`. A
separately-created account with a different number gives School A TWO accounts — one the
payment path made, one the seeder made — and copying that number formula into the seeder to
make them collide is a second copy of a rule, which is the exact thing this commit's trait
extraction argues against.

CLOSE IT WITH ONE SOURCE:
  - Extract the account creation from `bankAccountId()` into a method that can be called for
    a school that will never record a payment. Public on DriveFinanceStates is fine — it is a
    console fixture class, not a domain service.
  - `SeedDriveFixture` calls it for BOTH school ids before the state blocks run, inside the
    matching `ActiveSchool::runFor` if the model needs context.
  - `bankAccountId()` keeps working unchanged: same key, so it FINDS the row rather than
    making a second one. Confirm that by counting rows for school A after a full seed and
    reporting the number.
  - Add a "Bank accounts" column to the report table at :127-131 (same `$count` closure,
    table `finance_bank_accounts`), so the next drive reads the count instead of trusting
    the comment above it.

The docblock at DriveFinanceStates.php:39-42 says the account is created there "because this
class is the only thing that records payments, and a fixture account that exists but is never
used would be a row nobody could explain". That reasoning is now FALSE — the authoring screen
needs an account in a school that records no payment. Rewrite it; do not preserve it.


FIX 2 — THE TERM LABEL EXISTS THREE TIMES AND ONE COPY DISAGREES

`FeeScheduleResource.php:32-34` builds `"{session} — {term}"` under a comment saying "Two
screens naming the same term differently is how an operator picks the wrong one".
`routes/web.php:216` is the same expression. `FeeScheduleChangeResource.php:57` is
`$this->target?->term?->name` — the bare name.

The approvals queue is where the ED decides whether a schedule becomes billable. It shows
"First Term"; the new list shows "2026/2027 — First Term". With two sessions each carrying a
"First Term", the queue does not identify which — the confusion the new comment claims to
prevent, on the one screen where the decision is made.

The reviewer called this a ticket on the grounds that changing it touches a screen this commit
does not own. I checked the arm: `ApprovalsQueueRendersEveryTypeTest.php:415` asserts only
`toBeString()->not->toBe('')`, and `:422` uses the value as part of a uniqueness key.
`resources/js/lib/finance/approval-feeds.ts:207` joins the pair for display. Nothing breaks.
That makes it a small change, and a third copy carrying a comment that claims agreement it
does not have is worse than the change.

  - Add `Term::displayLabel(): string` on app/Models/Term.php — the trim/concat, with the
    `?? ''` on the session hop, in one place.
  - Point all three sites at it: FeeScheduleResource, routes/web.php:216,
    FeeScheduleChangeResource's `target_term`.
  - ONE arm asserting the three produce the same string for one term. It must build the
    expected value LITERALLY, not by calling displayLabel() — an arm that calls the thing it
    tests asserts nothing. `FeeScheduleTest.php:251` already does it the right way; keep that
    literal exactly as it is.


FIX 3 — THE TWO NEW LABELS DEREFERENCE A RELATION WITHOUT ?->

`FeeScheduleResource.php:33` is `.' — '.$this->term->name` and `:35` is
`fn () => $this->classLevel->name`. Neither guards the relation; only the `academicSession`
hop is guarded, by `??`. `whenLoaded()` is TRUE for a belongsTo that eager-loaded to null, so
a schedule whose slot is invisible under SchoolScope returns a 500 from the index rather than
a null label.

Ticket was defensible on exposure — the reviewer could not construct such a row under today's
validation and found none on the dev copy. I am calling it a fix on COHERENCE instead: three
lines below, this same commit wrote `$item->bankAccount?->uuid`. One resource that guards one
relation and not the other two is the shape I refused on #234's R1 — leaving one unguarded
after guarding the other is incoherent rather than merely incomplete.

  - `?->` on both.
  - NO ARM. `?->` is a language operator, not a rule; there is no bite to prove, and the row
    that would exercise it is not constructible through the validation. Say exactly that in
    the report rather than inventing an arm that asserts nothing.


FIX 5 — A COMMENT THIS COMMIT MADE FALSE

`tests/Feature/Finance/EditFeeScheduleDraftTest.php:356-358` reads "FeeScheduleRequest IS
REUSED, not re-implemented — so the rule #233 put on create bites on edit for free." As of
48279f5 the edit route is validated by EditFeeScheduleDraftRequest and the rule arrives
through HasFeeScheduleItemRules.

Ticket precedent exists (opening-balance-three-stale-comments.md), but that ticket is for
comments in files a commit did not touch. This commit edited this file. Fix it: rewrite the
three lines to name the trait, and say that this arm is what proves the trait move did not
drop the isolation rule — because per the review that is exactly what it is.


TICKET 4 — THE CHECK CONSTRAINT THAT IS IN THE LEDGER AND NOT IN THE SCHEMA

The report records the drift and files nothing. An implementation report is an archive, not a
queue; docs/handoff/tickets/ is where this repo tracks work, and a finding that lives only in
a report is the wallpaper case.

I could not reproduce this myself — there is no mysql client available to me — so file it as
the reviewer's reproduction, attributed, not as mine and not as yours:

  docs/handoff/tickets/term-date-order-check-absent-from-schema.md

Naming: the migration `2026_07_28_120000_add_term_date_order_check` present in `migrations` at
batch 11; `information_schema.CHECK_CONSTRAINTS` returning 15 constraints for the schema and 0
matching %term%; that the migration's ALTER at :34-40 is unconditional with no skip path;
that this is ADR 0052's rule in the flesh (verify by SHAPE, not by exit code); and the open
question — whether production is in the same state, which nobody has looked at and which is
the project lead's to answer, not a thing to check from a dev machine.


TICKET 6 — THE SHARED ITEM RULE'S ISOLATION HALF IS UNARMED

No arm asserts that ANOTHER school's bank-account uuid is refused, on create or on edit. The
review confirmed the rule text is byte-identical to base, so this commit did not open the gap
— but the trait move is the moment the rule became shared by two requests, and a shared rule
with an unarmed isolation half is precisely how a future edit to the trait goes unnoticed.

  docs/handoff/tickets/fee-schedule-item-bank-account-foreign-school-unarmed.md

Ticket, not fix: nothing is wrong, the rule is correct, and arming it is a test-writing task
with its own fixture work. Do not grow this commit with it.


ADD ONE ARM — THE EMPTY FILTER

`?term_id=` arrives as null through ConvertEmptyStringsToNull and therefore means "unfiltered".
The review confirmed that middleware is in the default global stack and not excluded in
bootstrap/app.php, so the claim is true. No arm asserts it, and it is the one behaviour the
`nullable` choice exists for.

One arm: index with `?term_id=` and `?status=` empty returns the same set as index with no
query string at all. Watched red by swapping `nullable` for `required` on either rule.


TWO CORRECTIONS TO THE REPORT

The report is a dated act — do not rewrite its body. Append a CORRECTIONS section at the top,
dated, naming both:

  1. The deleted controller docblock claimed the `term_id`/`class_level_id` exists rules were
     "UNSCOPED" and "not harmless on store/supersede". That claim was FALSE at the base:
     `git show 59e1da8:app/Finance/Http/Requests/FeeScheduleRequest.php` shows both already
     carrying `->where('school_id', ActiveSchool::id())`. Deleting the docblock was correct;
     the report presents its deletion as removing a stale contract when what it removed was a
     false statement, and those are different things. The false comment came in with #234 and
     was not swept when #235's R1 scoped the rules.
  2. The report cites `git diff --stat 59e1da8..HEAD` for "12 files, +497/−119". That command
     yields 13 files and +883, because HEAD includes the report itself. The figures are right
     for `59e1da8..48279f5`; the command naming them is not. Correct the command, keep the
     figures, and say which was wrong.


GATE

  bin/quality

Raw, 14 steps, unedited. Two runs reported as two runs if the first is red.


REPORT

Append to the existing report under a dated heading — do not start a new file and do not
rewrite what is there.

Facts, evidence, deviations, and what you could not verify. Output pasted raw; long output cut
by whole lines with the cut marked.

No severity calls on your own work. Do not nominate what you think is contentious.

For FIX 1, state the School A bank-account row count after a full seed, and paste the new
four-column table.
```

---

## Block 3 — the final pass on the second cold review (2026-08-11)

```text
FINAL PASS — U1 commit 1, feat/fee-schedules-data-surface

Second cold review, five findings, all reproduce against b141e89. I verified each myself.

Four are FIXES here, one is a ticket, one is a run. Three of the four fixes are comment
corrections — which is not busywork in a commit whose CORRECTIONS section is about false
comments. Leaving them would be incoherent.

Amend into b141e89 and rewrite 16d18ff. This is the last pass on commit 1.


FIX A — THE THREE WRITE ROUTES RETURN NO LABELS

`CreateFeeSchedule.php:85` and `EditFeeScheduleDraft.php:90` both return
`->load(['items' => fn ($q) => $q->orderBy('sort_order')])` — no `term`, no `classLevel`.
The controller then adds only `loadMissing('items.bankAccount')` at `:86` (store, 201),
`:115` (editDraft, 200) and `:142` (supersede, 200). So `index` returns `term_label` and
`class_level_label` and those three return neither.

Commit 2's screen renders a row from the list, the operator saves an edit, the client
re-renders that row from the PUT response, and the term and class-level columns go blank.
That is the failure the prefill key-list arm exists to prevent, one route over — and the
PUT response is the single most likely thing a page re-renders from.

The reviewer called this a ticket because commit 2 owns the screen. I am calling it a fix
because the correction is three tokens and filing it costs more than doing it.

  - `loadMissing('items.bankAccount', 'term.academicSession', 'classLevel')` at all three
    controller sites. NOT in the Actions — the Action's return value is its contract with
    every caller including tests, and the render shape is the controller's business.
  - One key-list assertion of the SAME SHAPE as the prefill arm, over all three routes:
    the response's schedule object carries both labels. Watched red by dropping the two
    relations from one of the three, so the arm names which route lost them.


FIX B — THE whenLoaded RATIONALE IS FALSE, AND IT IS MINE

`FeeScheduleResource.php:32-36` says "whenLoaded() is TRUE for a belongsTo that eager-loaded
to NULL, so a schedule whose slot is invisible under SchoolScope would otherwise return a 500".

That is wrong, and the error originated in MY remediation block, not in your work. Read
vendor/laravel/framework/src/Illuminate/Http/Resources/ConditionallyLoadsAttributes.php:272-293:

    $loadedValue = $this->resource->{$relationship};
    if (func_num_args() === 1) { return $loadedValue; }
    if ($loadedValue === null) { return; }

With two arguments and a loaded-null relation, it returns null at :285 and the closure is
never evaluated. There is no path on which `$this->term?->displayLabel()` could have raised.

KEEP THE `?->`. The other half of the reasoning still holds — `$item->bankAccount?->uuid` is
three lines below and guarding one relation and not the others reads as an oversight.
Reverting a green branch to remove two inert characters is churn.

REWRITE THE REASON, and reconcile it with the sentence four lines above it, which the same
vendor code also makes wrong in the other direction:

  - :25-26 says "present-and-null would be a claim that the schedule has no term". For an
    UNLOADED relation whenLoaded returns `value($default)` = MissingValue, so the key is
    ABSENT — that half is right. For a LOADED-NULL relation it returns null, so the key is
    PRESENT-AND-NULL and that outcome is reachable. The two sentences currently describe
    different cases as though they were one.
  - Say what is true for both cases, in one block: unloaded → key absent; loaded-null → key
    present and null; the `?->` is coherence with the bankAccount line and buys nothing at
    runtime.

Do not soften this into "clarified". The comment stated a framework behaviour that does not
exist, and the report will carry the correction under my name — see CORRECTIONS below.


FIX C — Term::displayLabel()'s DOCBLOCK MAKES TWO CLAIMS THE REPO CONTRADICTS

(i) `Term.php:54` — "HOW A TERM IS NAMED TO A HUMAN, in one place". Verified against the
tree, there are three more expressions, all in the opposite word order with a different
separator:

    app/Http/Resources/TermResource.php:20   $this->name.' - '.$this->academicSession->name
    app/Services/BroadsheetService.php:65    $term->name.' - '.$term->academicSession->name
    app/Services/BroadsheetService.php:163   same

So one term reads "2026/2027 — First Term" on the fee-schedules list and
"First Term - 2026/2027" on a broadsheet. Narrow the claim to the three finance-adjacent
screens it is actually true of, and NAME those three sites as surfaces that deliberately do
not use it, pointing at the ticket below. A future author who reads "in one place" and points
TermResource::full_name at this method silently changes result screens and exported
broadsheets.

(ii) `Term.php:63-65` — "an unloaded or out-of-scope session must degrade to ' — First Term'
trimmed rather than raise". `:69` is `$this->academicSession->name ?? ''`, which on an
UNLOADED relation lazy-loads it and returns the real name. Confirm for yourself that nothing
calls `preventLazyLoading`/`shouldBeStrict` (grep app/ bootstrap/) and paste what you find.
Only the out-of-scope half is true. Correct it to say the session hop LAZY-LOADS when
unloaded and degrades only when the relation resolves to null — otherwise a future author
reads "unloaded degrades" and drops the eager load that
FeeScheduleChangeController::pending():78 exists to keep.


FIX D — A CROSS-REFERENCE THIS COMMIT BROKE

`FeeScheduleRequest.php:52-53` — "Same shape and same reason as `items.*.bank_account_id`
below". It is no longer below; this commit moved it to
`HasFeeScheduleItemRules.php:31-37`. A reader checking the stated three-way symmetry finds
two rules and no third, and cannot tell whether the split dropped it or relocated it — the
one question the split most needs answerable at a glance.

  `below` → `in {@see HasFeeScheduleItemRules}`.


RUN — THE FOURTH COLUMN'S RED

RED 6 mutates DriveCastSeeder::run() and shows the academic three going to 0/0/0, with the
stated purpose "proves the counts are read from the database rather than printed from the
seeder's own intent". The Bank accounts column was then added through a different path
(SeedDriveFixture.php:139 → DriveFinanceStates::bankAccountCount(), inside runFor) and has
only ever been observed at 1.

Run the mutation: remove the `ensureBankAccount` calls, seed, paste the table showing the
column at 0/0, restore, seed, paste it at 1/1. Two pastes. The standard was set by the report
for the other three columns and the column added under it should meet it.


TICKET — THE PLATFORM NAMES A TERM TWO WAYS

  docs/handoff/tickets/term-label-two-formats-across-the-platform.md

Naming: `Term::displayLabel()` and the three finance-adjacent readers on one side;
`TermResource:20` and `BroadsheetService:65,:163` on the other; that the difference is word
order AND separator, so the two are not interchangeable; and that converging them changes
what renders on result screens and in exported broadsheets, which is a product decision and
not a code cleanup.

Ticket, not fix, for that last reason alone.


CORRECTIONS — APPEND TO THE EXISTING SECTION, DATED

  3. The rationale given for `?->` on the two labels — that whenLoaded is true for a
     loaded-null relation and the index would otherwise 500 — is FALSE.
     ConditionallyLoadsAttributes.php:284-286 returns null before the closure runs. The
     error originated in the advisor's remediation block, which asserted the framework's
     behaviour without reading vendor, and this commit implemented it as written. The `?->`
     is retained for coherence with `$item->bankAccount?->uuid`; the reason is corrected.
     Attribute it to the block, not to the implementation.


GATE

  bin/quality

Raw, 14 steps, unedited. Two runs reported as two if the first is red.


REPORT

Append under a dated heading. Facts, evidence, deviations, what you could not verify. Raw
output, cuts by whole lines and marked. No severity calls on your own work.

Paste the two drive tables (0/0 and 1/1) and the preventLazyLoading grep result whatever it
shows, including if it shows nothing.
```

---

## Block 4 — the last pass on the third cold review (2026-08-11)

Not named among the three blocks the instruction listed, and included anyway: it is the block that
asked for this file, and the stated purpose of the file — that a reviewer can diff what was asked
against what was built — fails for the last four fixes if the block asking for them is the one thing
missing. Reproduced under the same rule as the others.

```text
LAST PASS — U1 commit 1, feat/fee-schedules-data-surface

Third cold review, four findings, all reproduce against 6abe3db. All four are FIXES; no
tickets. Amend into 6abe3db, rewrite 78386ca.

This is the final pass on commit 1. It ships after this regardless of what a fourth review
would say.


FIX A — THE INDEX ISOLATION RULE'S STATED REASON IS FALSE, AND THE COMMENT REFUTES ITSELF

FeeScheduleController.php:35-38 says an unscoped term_id "would be a 200 telling the caller
that another School's term has no schedules, which is an answer about another School's data",
and then adds "(The rows themselves are safe either way — SchoolScope bounds them …)".

The parenthetical is true, and it is what makes the sentence above it false. `FeeSchedule`
uses BelongsToSchool (app/Finance/Models/FeeSchedule.php:6, :29), so index() is bounded to the
active School before `where('term_id', …)` is applied. The response is `200 []` whether School
B's term has zero schedules or fifty. Nothing about School B is conveyed.

WHAT THE SCOPING ACTUALLY CLOSES — write this, because it is nowhere in the repo:
an unscoped `Rule::exists('terms','id')` distinguishes 422 (this term id exists NOWHERE on the
platform) from 200-empty (it exists in SOME school). That is a term-id existence oracle across
every school, and it is the whole of the control. The row-level answer is empty either way.

  - Rewrite FeeScheduleController.php:35-38 to name the existence oracle and drop the
    "another School's term has no schedules" framing entirely.
  - Rewrite FeeScheduleTest.php:316-319 the same way. Its current text — "the caller is then
    told, truthfully, how many schedules another School's term has" — is the same false claim
    in the file whose job is to explain why the assertion matters.
  - The assertion needs NO change. RED 5 stays as it is; only its interpretation was wrong.

Say plainly in the report that the guard was right and the reason was wrong, and that the two
are different failures.


FIX B — CUT THE whenLoaded COMMENT DOWN

FeeScheduleResource.php:22-45 is now roughly twenty lines about one framework method,
including a paragraph beginning "AN EARLIER VERSION OF THIS COMMENT SAID". It is the largest
thing in the file, it is on its third revision, and two of the three shipped a false claim.

The current revision carries a fresh one: it says the present-and-null outcome "IS reachable —
a schedule whose term or class level is invisible under SchoolScope eager-loads to null".
The report's own FIX 3 section says of the same state that "that row is not constructible
through today's validation (both `exists` rules are School-scoped)". Both are in this commit
and they disagree.

The fix is not a fourth revision. It is a cut. Replace the whole block with FOUR LINES, no
more, saying only:

  - unloaded → key absent (this is prefill);
  - loaded-null → key present and null, before the closure runs (vendor
    ConditionallyLoadsAttributes.php:284-286);
  - no write path in this repo produces a loaded-null term or class level, both `exists` rules
    being School-scoped — so this is a shape guarantee, not an observed case;
  - the `?->` is inert and kept for coherence with `$item->bankAccount?->uuid`.

DELETE the "AN EARLIER VERSION OF THIS COMMENT SAID" paragraph. A comment's changelog belongs
in the report — CORRECTION 3 already carries it — not in a resource. Source that narrates its
own revisions is how a file ends up with more commentary than code.


FIX C — routes/web.php:199 STATES A FALSE SUBSTRATE FACT

"`terms` is not a BelongsToSchool model, so this one is written rather than inherited."
app/Models/Term.php:16 is `use BelongsToSchool, LogsActivity;`.

Pre-existing at 59e1da8 — confirm that yourself with `git show` and say so — but this commit
edited inside that same closure at :218 and converged three term-label sites, so it read past
the line.

Correct it to what is true: Term DOES carry BelongsToSchool, the explicit `where` is redundant
with SchoolScope and deliberate rather than compensating, and say in one clause why it is kept
(the route runs inside `tenant`, and an explicit predicate on a props query is readable at the
call site). Do not silently delete the `where`.


FIX D — COMMIT THE U1 BRIEF

docs/handoff/ carries about thirty briefs; there is no U1 one. The report's "Deviations from
the brief" and "Contradictions of the premise" therefore quote a document no reviewer can
read, which makes the one attack-order item that catches a faithful implementation of a false
premise uncheckable by anyone but its author. That is the advisor's process gap, not yours.

  docs/handoff/u1-fee-schedules-brief.md

Assemble it from the three blocks you were sent in this chat — the commit-1 brief, the
remediation block, and the final-pass block — REPRODUCED VERBATIM under dated headings, in the
order they arrived. Do not paraphrase, do not tidy, do not merge them into a narrative: the
value of the file is that a reviewer can diff what was asked against what was built, and a
summary destroys exactly that. Add one header line stating that commit 2 (the route, the page,
the sidebar entry) is not yet written and is not covered by this file.


GATE

  bin/quality

Raw, 14 steps, unedited. Two runs reported as two if the first is red.


REPORT

Append under a dated heading. Facts, evidence, deviations, what you could not verify. Raw
output, cuts by whole lines and marked. No severity calls on your own work.

For FIX A, paste the two rewritten passages in full — they are the deliverable.
```

---

## Block 5 — the commit-2 brief (2026-08-11)

The screen itself: the route, `fee-schedules.tsx`, the sidebar entry, the one endpoint change
(the schedule total), the two gates that fire, the fixture regeneration and a browser drive. This
is the block that closes the gap the header above used to name.

```text
CONTEXT

Branch off `staging` AFTER the U1 commit-1 PR is merged. Confirm first:

  git fetch origin && git log --oneline origin/staging -3

`origin/staging` must contain the fee-schedules data surface commit. If it does not, stop.

  git switch -c feat/fee-schedules-screen origin/staging

Read docs/handoff/u1-fee-schedules-brief.md before you start. It is the four blocks that
produced commit 1, verbatim, and it states that commit 2 is not covered by it. This block is
commit 2. Append it to that file as Block 5 as part of this commit.

This is ONE commit. Route, page, sidebar, the two gates that fire, the fixture regeneration,
and a browser drive.


WHAT EXISTS AND WHAT DOES NOT

Every endpoint is built: index (with term_id/status filters), prefill, store, editDraft,
supersede, and the publish/retire change flow. The resource carries term_label,
class_level_label and each item's bank_account_id. A fee schedule can be authored today only
with curl. This commit is the screen. No new endpoint, with ONE exception named below.


1. THE ROUTE

routes/web.php, inside the existing `['auth','tenant','permission:finance.access']` group.

  Route::get('/finance/fee-schedules', …)
      ->middleware('permission:finance.fee-schedule.manage')
      ->name('admin.finance.fee-schedules');

The extra permission is the bank-accounts precedent (routes/web.php:159-161) and the same
reasoning: this is AUTHORING, not viewing. Everyone who can read finance must not be offered
a screen that sets prices. The nav entry keys on the same ability so a visible item can never
403 on click.

TERMS AND CLASS LEVELS ARE PROPS, NOT A FETCH. Same reason as the opening-balance operator
screen: the only API listing terms is gated on `academic_data.view`, which the finance seat
does not hold. Widening that seat or coining a finance-side terms endpoint are both bigger
changes than the screen.

  - `ActiveSchool::getOrFail()->id` — the INT, not the model. Binding the model into a
    `where('school_id', …)` matched nothing and rendered an EMPTY term select on the
    opening-balance screen, and every test still passed. routes/web.php:207-213 carries that
    scar; do not reopen it.
  - Terms: `->with('academicSession')`, ordered id desc, labelled with `Term::displayLabel()`
    — the method, not a fourth copy of the expression.
  - Class levels: scoped to the School, ordered by `order`, `{id, name}`.

BANK ACCOUNTS ARE A FETCH, NOT PROPS — and this is a decision, not an omission.
`GET /v1/finance/bank-accounts` is gated on `finance.bank-account.manage`, a DIFFERENT ability
from the one gating this page. Today every role holding `finance.fee-schedule.manage` also
holds `finance.bank-account.manage` (admin at RbacSeeder.php:235-236, accounts_officer at
:381-382), so the fetch cannot 403. Props are for data the seat CANNOT fetch; accounts are
data it can, and a second source for them is the drift shape.

Turn that implicit coupling into a checked one: ONE arm asserting that every role in
grantsMap() holding `finance.fee-schedule.manage` also holds `finance.bank-account.manage`,
with a message saying the fee-schedules screen's account picker fetches an endpoint gated on
the latter and would render empty-and-broken for a holder of only the former. Watched red by
removing the bank-account grant from one role in the map.


2. THE ONE ENDPOINT CHANGE — THE SCHEDULE TOTAL

resources/js/lib/format.ts states the rule and bin/ci-money-lint.php enforces it: the frontend
performs NO monetary arithmetic, because JS numbers are floats and the backend moved to integer
minor units precisely to avoid that. The API returns every total already computed; the UI only
displays.

So the page CANNOT sum a schedule's items, and a schedule list without a total is close to
useless to the person deciding whether to submit it for approval.

  - `FeeScheduleResource` gains `total`, the Money wire shape {amount_minor, currency},
    summed in PHP from the items. Under `whenLoaded('items', …)` — the same treatment the
    items themselves get, so prefill's payload does not grow a key.
  - Sum through `App\Support\Money`, not by adding ints. If the items disagree on currency
    that is a condition worth surfacing rather than silently adding — decide what it does and
    say why in the report.
  - Extend the existing prefill key-list arm to prove `total` is ABSENT there, and add an
    index arm asserting the value. Watched red on both.
  - The page renders it with `formatNaira`. It never computes it.


3. THE PAGE

resources/js/pages/admin/finance/fee-schedules.tsx

The shape precedent is resources/js/pages/admin/finance/bank-accounts.tsx — read it first.
Same layout export (`FeeSchedules.layout = { breadcrumbs: [...] }`), same axios + modal +
422-bag pattern, same `@/components/ui` imports.

FIVE ACTS, AND NO SIXTH:

  a. LIST. Filter by term and status through the query params commit 1 added — server-side,
     not by fetching everything and filtering in the browser. Columns: label, term_label,
     class_level_label, status, item count, total. Default filter: no term (all), because a
     school arriving in September has one term of schedules and hiding them behind a
     preselected filter is worse than a short list.

  b. AUTHOR A DRAFT. Modal: term select, class level select, label, and item rows —
     description, amount, bank account select, mandatory, discountable. POST to
     /api/v1/finance/fee-schedules.

  c. EDIT A DRAFT. The same modal, prefilled from the row INCLUDING each item's
     bank_account_id (this is what commit 1 added the field for). PUT to
     /api/v1/finance/fee-schedules/{uuid}/draft. It sends label and items ONLY —
     EditFeeScheduleDraftRequest carries no term_id or class_level_id and sending them is
     harmless but wrong.

  d. SUBMIT FOR APPROVAL. POST /api/v1/finance/fee-schedule-changes with kind=publish, the
     schedule uuid as `target`, and a REASON — required, max 255, and the ED reads it.

  e. RETIRE. Same endpoint, kind=retire. Active schedules only.

NO APPROVE, NO REJECT. That is /finance/approvals and duplicating the checker surface here
would give a second place for the ED's decision to live.

BUTTONS BY STATUS — the Action already refuses the wrong ones (SubmitFeeScheduleChange:49-53),
so this is about not offering an operator a button that 422s:
  draft            → Edit, Submit for approval
  pending_approval → nothing; show that it is with the ED
  active           → Retire; and a path to author a superseding draft (items are frozen —
                     re-pricing is a NEW draft plus a publish, per FeeScheduleStatus's docblock)
  superseded, retired → read only

MONEY:
  - `nairaToMinor` from '@/lib/format' for input, `formatNaira` for display. Both already
    exist. Do NOT write `* 100`, `parseFloat`, `toFixed` or `Intl` anywhere in this file —
    bin/ci-money-lint.php is a gate step and will refuse it.
  - nairaToMinor returns null on malformed input. That is inline validation, not a crash:
    show it on the row, do not submit.

THE 422 BAG IS NESTED, and this is the real difference from bank-accounts.tsx. Laravel returns
keys like `items.0.bank_account_id` and `items.2.amount_minor`. Mapping them flat the way
bank-accounts.tsx does puts every item error in one place with no indication of WHICH line is
wrong — on a form with eight fee lines that is an operator staring at a red box. Parse the
index out and render the message beside its row.

THE SLOT COLLISION. finance_fee_schedules_pending_unique permits at most one draft-or-pending
schedule per (school, term, class level). Authoring a second one for an occupied slot fails.
DETERMINE WHAT THE API ACTUALLY RETURNS for that case — read the code and provoke it, do not
assume it is a 422 with a usable message — and make the page say something actionable ("there
is already an open schedule for this term and class level; edit that one"). Report what you
found, including if it is a raw 500, in which case say so rather than papering it.

TOASTS: bank-accounts.tsx imports `toast` from 'sonner'; opening-balances/import.tsx imports
from 'react-toastify'. Both are live in this repo. Pick ONE, say in the report which and why,
and do not introduce a third.

No localStorage, no sessionStorage.


4. THE TWO GATES THAT FIRE THE MOMENT THE ROUTE REGISTERS

  a. tests/Feature/Finance/FinanceNavCoverageTest.php fails unless app-sidebar.tsx contains
     the literal quoted string '/finance/fee-schedules'. Add the item to the Finance group
     beside Bank accounts, gated on `can('finance.fee-schedule.manage')` — the same ability
     as the route, for the reason the bank-accounts comment there already gives.

  b. tests/fixtures/route-access-map.json gains `GET /finance/fee-schedules`. Regenerate it,
     do not hand-edit:

         php artisan rbac:sync
         php artisan rbac:derive-access

     rbac:derive-access reads grants from the CONNECTED DATABASE (RbacDeriveAccessMap.php:14),
     so syncing first is not optional — an un-synced database bakes the wrong access set into
     the fixture. UNVERIFIED: I have not run either command; treat this as the sequence the
     source describes and report what actually happened.

No new permission is coined, so app/Enums/Permission.php, PermissionGroup, grantsMap() and
rbac-grants-baseline.json are all untouched. If you find yourself editing one, stop and say why.


5. DRIVE IT

The fixture now has what it needs — commit 1 seeded the academic slot and a bank account per
school, and the report table prints 1/1/2/1.

  php artisan drive:seed   (or whatever the command is — read app/Console/Commands/SeedDriveFixture.php)

Drive as TWO seats and report both:
  - maker@drive.test (accounts_officer) — author a draft end to end, edit it, submit it for
    approval. Screenshots or a GIF.
  - school-b@drive.test (the isolation seat) — the term select, the class level select and the
    bank account picker must all be populated with SCHOOL B's rows and none of School A's.

Report what the selects actually contained, by count and by label. This is the check that a
passing test suite cannot make.


GATE

  bin/quality

Raw, 14 steps, unedited. Two runs reported as two if the first is red. Note that this commit
touches TypeScript, so tsc-ratchet and lint-changed are live in a way they were not for
commit 1.


REPORT

docs/handoff/reports/, the established shape. Facts, evidence, deviations, what you could not
verify. Raw output, cuts by whole lines and marked. No severity calls on your own work, no
nominating what you think is contentious, no references to material the reader does not have.

Append this block verbatim to docs/handoff/u1-fee-schedules-brief.md as Block 5, dated, and
correct that file's header line which currently says commit 2 is not covered by it.
```
