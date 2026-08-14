# chore/finance-drive-skill — the browser drive becomes a skill

**Branch:** `chore/finance-drive-skill`, off `origin/staging` @ `8af8d3a`.
**Commit:** `bfccb62`. One commit, six files, `+58` lines across five existing files plus one new
file. No app code, no test changes, no migration.

## Headline

Done. `.claude/skills/finance-drive/SKILL.md` now carries the browser-drive procedure that every
brief has been re-specifying since July. `finance-execute` and both its templates ask for a drive in
three lines and point there; `docs/finance/drive-environment.md` stays the environment doc and points
at the skill for the procedure. One brief carrying a full `DRIVE` section was **deliberately not
rewritten** — see "What I left behind".

## Deviations from the brief

Two, both about where the pointer went.

**1. There was almost nothing to re-point.** The brief expected a sweep of `docs/handoff/` briefs
carrying their own `DRIVE` section. `grep -rn -i drive docs/handoff/*.md` returns 52 matches across
11 files, and exactly **one** of them is a procedure section: `u1-fee-schedules-brief.md:881` (`5.
DRIVE IT`, inside Block 5). Everything else is either a one-line acceptance criterion in an executed
brief (`c5-brief.md:88` "drive the page in the running app", `c6-brief.md:88` "Floor 10/10; drive in
the running app"), a reference to the fixture's source files
(`credit-note-approver-move-brief.md:143`, `executive-director-role-brief.md:237`), a *report* of a
drive rather than an instruction to do one, or an unrelated use of the word.

The duplication the brief describes is real — it just lives in the **reports**, not the briefs. Five
report files carry a `## The browser drive` section and each rediscovered the same environment facts
independently. So the pointer went where the next brief is *written* from — `finance-execute` and its
two templates — rather than into historic briefs where it would change nothing.

**2. I edited two files the brief did not name.** `docs/finance/drive-environment.md` gained a
five-line pointer at the top, because it is the file anyone looking for "how do I drive this" lands
on first and it would otherwise silently compete with the skill. `docs/handoff/u1-fee-schedules-brief.md`
gained a header paragraph — **not** an edit to its `DRIVE IT` section; see below. Both are pointers,
not substance. Neither is app code.

## What the skill says

`.claude/skills/finance-drive/SKILL.md`, seven sections, written in `finance-execute`'s voice —
argued, with the reason beside each instruction, and every load-bearing claim carrying a `path:LINE`.

**What a drive is for.** The suite is structurally blind to rendering
(`docs/finance/drive-environment.md:3-6`): a 200 with the right list, a 200 with an empty list, and a
200 rendering an error where a list should be are the same assertion. Two evidenced cases, both green
in every test:

- **The opening-balance operator screen**
  (`docs/handoff/reports/feat-finance-ob-operator-screen.md:200-215`) — `routes/web.php` bound
  `ActiveSchool::getOrFail()`, a **School model**, into `where('school_id', …)` where the int was
  wanted; it matched nothing, the term select was empty, the form could never be submitted, and every
  assertion passed because the assertions asserted that the screen *renders*. Separately, `store`
  answered without `rejected_rows` while the page read `active.rejected_rows.length`, blanking the
  screen with a `TypeError`. That commit was the fourth in its feature to defer the drive and the
  first to run it.
- **U1's fee-schedules screen**, where the same class landed one layer out — **in the fixture**.
  `DriveCastSeeder` seeded no academic session, term or class level
  (`DriveCastSeeder.php:91-97`), and School B had no bank account because its only state was a
  `plainInvoice`, which records no payment
  (`docs/handoff/reports/feat-fee-schedules-data-surface.md:437-450`). The drive would have opened
  onto three empty selects and authored nothing, and no test could see it because tests build their
  own rows. **Correction to the brief's framing:** both were caught by *reading the seeder* before
  the drive, not by the drive. I kept them as the second piece of evidence anyway, and said so
  explicitly — because that is precisely why "check the count table first" is a section of its own
  and comes before "open a browser".

**The environment.** Points at `docs/finance/drive-environment.md` rather than restating it. Names
the two structural guards — `APP_ENV=drive` (`SeedDriveFixture.php:49-54`) and the database-name
**allowlist** `/(^|_)drive(_|$)/` (`SeedDriveFixture.php:44`, `:56-63`) — and why they are structural
(the command `migrate:fresh`-es).

**The fixture check, before the browser.** The command's own count table
(`SeedDriveFixture.php:155-162`) — academic sessions, terms, class levels, bank accounts, discount
policies per school — counted from the database rather than from the seeder's variables, which would
only ever report what the seeder intended (`SeedDriveFixture.php:130-153`). The rule stated as the
source states it: **a zero in any column means the screen under drive cannot author anything, and
the drive is worthless before it starts** (`SeedDriveFixture.php:135-137`). Plus the consequence the
history shows: if your screen needs something the table does not count, the table needs a column
before your drive needs a browser — that is how the bank-accounts and discount-policies columns
arrived.

**The seats.** A table read off `DriveCastSeeder::seedCast()` (`:141-167`), with what each one
*proves* rather than what it *is*. The void-only checker's entry carries why it exists: the first
drive found `/finance/approvals` gated on a single permission, so the unified queue's per-feed
403-tolerance never executed and a partial checker got a full-page 403
(`docs/handoff/drives/2026-07-25/README.md:30-46`). The password is named as
`DriveCastSeeder::PASSWORD` (`:35`) rather than pasted, and the skill says why — a pasted constant
goes stale silently. It also flags that `checker@drive.test` changed role on 2026-08-04
(`DriveCastSeeder.php:144-146`), so a brief naming a role for a seat should be checked against the
seeder. And it carries the segregation-of-duties fact: maker and checker cannot be one login,
`User::assignRole` throws below every path, no flag and no `--force`
(`docs/finance/drive-environment.md:63-72`).

**Isolation by id, never by label.** `seedAcademicSlot()` runs identically for both schools
(`DriveCastSeeder.php:111-139`) — both get `2026/2027`, `First Term`, `JSS 1`, `JSS 2` — so the
labels are identical strings *by construction* and a screen showing "First Term" proves nothing. The
method is U1's, quoted with its numbers
(`docs/handoff/reports/feat-fee-schedules-screen.md:258-303`): term `1` against term `2`, levels
`1,2` against `3,4`, two account uuids, three label strings matching character for character. Read
option **values**, not option text; put both seats side by side; then check the second half — School
A's new row absent from School B's list.

**Friction, already paid for.** Six items, each with the session it cost:

| Friction | Source |
| --- | --- |
| Assets must be genuinely built or every Inertia page 500s; `public/build` is gitignored and only `bin/quality:195` builds it; **do not fabricate a manifest** | `docs/handoff/tickets/fresh-clone-review-needs-a-built-manifest.md` |
| `:8001` must be in `SANCTUM_STATEFUL_DOMAINS` or every finance API call 401s and every statement renders "Could not load the statement" | `.env.drive.example:40`, `drives/2026-07-25/README.md:77-83` |
| `php artisan serve` is single-threaded; the SPA can lose the CSRF race on first paint — reload once rather than reporting the error state | same |
| Measure after the redirect settles — a phantom `sidebar entry present: false` came from counting links while the title still read "Log in" | `feat-finance-bank-accounts.md:224-230` |
| Install the browser outside the repository; its download must not land in `node_modules` | `feat-finance-ob-operator-screen.md:191-193` |
| `page.request.get()` returns 401 under Playwright — no `Referer`, so Sanctum is not stateful. Harness artifact; click the real button | `feat-finance-ob-operator-screen.md:299-301` |

**What to report.** Five numbered items: the count table pasted; what the selects **contained by
count and by value**, raw and uncut, because a summary of what a select contained is a claim about
what it contained; what each observation establishes including the arithmetic where a total is on
screen; both seats side by side with ids visible; and **what was not driven**, which on every drive
so far has been the lifecycle states the fixture cannot reach — the retire and supersede paths that
need an *active* schedule, a *rejected* proposal, the opening-balance approve. Screenshots go in
`docs/handoff/drives/<date>-<screen>/`, named so a reader knows what each shows without opening it.

**The boundary.** A drive observes; it does not fix — *a drive that repairs what it finds destroys
the evidence* (`docs/handoff/drives/2026-07-25/README.md:28`). Two narrow exceptions, both already
exercised and both named: fixing the **fixture** when it cannot reach the state you were told to
drive (a precondition, not a finding — U1 commit 1 is the precedent), and a **drive-environment
config** change such as the Sanctum entry. Everything else is reported and left alone; the decision
is the project lead's.

## Where past drives disagreed, and what I picked

**Which database.** The 2026-08-09 drives of the sidebar
(`feat-finance-sidebar-section.md:167`), the fail-closed RBAC change
(`feat-rbac-fail-closed-finance.md:442`) and the opening-balance operator screen
(`feat-finance-ob-operator-screen.md:189`) all ran against the **local production copy**. The
bank-accounts report of the same day is titled *"The browser drive — portal_drive, never the
production copy"* (`:200`), and both drives since — fee-schedules 2026-08-11, discount-policies
2026-08-13 — used the fixture.

**Picked: the fixture, never a production copy.** The reason is what the copy-based drives cost, and
the skill states it rather than asserting a preference:

- One left five `DRIVE-*` batches and two minted users behind in `school#1`
  (`feat-finance-ob-operator-screen.md:283-295`).
- One could not drive its `super_admin` seat at all, because signing in as a real user needed a
  **credential write on a production copy**; the environment refused it and the drive did not route
  around it (`feat-rbac-fail-closed-finance.md:469-476`).
- The opening-balance **approve** path is undriven to this day, because approving there consumes that
  school's single posting slot permanently — no un-post, no delete, no move.

A throwaway database is one you are willing to spend. That is the whole argument, and the two later
drives are the evidence it works.

## What was re-pointed

| File | What changed |
| --- | --- |
| `.claude/skills/finance-drive/SKILL.md` | **New.** The skill. |
| `.claude/skills/finance-execute/SKILL.md` | Writer side: "The drive is a pointer, not a section" — ask in three lines, do not re-specify the procedure, with the reason. Implementer side: "If a screen changed, drive it — and load `finance-drive` before you do", with the blindness argument. |
| `.claude/skills/finance-execute/references/brief-template.md` | New `## Part 4 — drive it` — a three-line shape (screen, seats, screen-specific things to look at) that explicitly says the procedure is the skill and must not be restated. |
| `.claude/skills/finance-execute/references/report-template.md` | New `## The drive` section, listing what it carries and instructing that it is deleted only if no screen changed — *"if a screen changed and you did not drive it, say that here and say why"*. |
| `docs/finance/drive-environment.md` | Five-line block at the top: this file is the **environment**, the skill is the **procedure**. No other change. |
| `docs/handoff/u1-fee-schedules-brief.md` | Header paragraph only. See below. |

## What I left behind, and why

**`u1-fee-schedules-brief.md`'s `DRIVE IT` section is untouched.** It is the only real instance of
the thing the brief asked me to replace, and replacing it would have been wrong. That file's own
header states its contract: *"Nothing below is paraphrased, tidied or merged into a narrative"* and
*"A reviewer's first attack is whether a faithful implementation was built on a false premise — the
one failure that passes every other check. That attack needs the text that was asked for, not a
summary of it."* Rewriting a fenced verbatim block to point at a skill that did not exist when the
block was written falsifies the record the file exists to keep.

What I did instead: a paragraph in the **header**, outside every fence, saying the section is
superseded, where the procedure now lives, and why the section was left standing. Editing that header
has precedent in the file itself — lines 8-12 record an earlier commit correcting it.

**`c5-brief.md:88` and `c6-brief.md:88` are untouched.** One-line acceptance criteria ("drive the
page in the running app") in briefs that were executed months ago, on RBAC screens, predating the
drive fixture. They carry no procedure, so there is nothing to replace; re-pointing them is churn on
completed work.

**The manifest ticket is still open, and its own requirement is unmet.**
`docs/handoff/tickets/fresh-clone-review-needs-a-built-manifest.md` asks for a setup step in
`.claude/skills/finance-review/SKILL.md` — so a **cold reviewer running a filtered arm** does not read
a 500 where an assertion should be. I folded what it records into `finance-drive`, as instructed, but
that covers the *drive*, not the *review*. `finance-review` was not edited and the ticket should not
be read as closed.

## What I found in past drive reports that was worth preserving

Four things that lived in exactly one report each and would have been lost the next time someone
went looking:

1. **The Sanctum stateful origin and the CSRF race.** Both in
   `docs/handoff/drives/2026-07-25/README.md:77-83` — the first drive ever run — and in no report
   since. The first is already fixed in the committed `.env.drive.example:40`, so it only bites
   someone who built `.env.drive` from something else; the second is unfixed and will bite everyone.
2. **The measurement artifact.** `feat-finance-bank-accounts.md:224-230` records a drive reporting
   `sidebar entry present: false`, then correcting itself: the count was taken before the
   post-login redirect settled, while the title still read "Log in". Worth preserving because the
   failure mode is a **phantom defect filed against someone else's change**, and it reads as a real
   finding at the time.
3. **`page.request.get()` 401s under Playwright.** `feat-finance-ob-operator-screen.md:299-301`, one
   line, correctly classified there as a harness artifact rather than a defect. A future drive
   hitting it without this would file it.
4. **The boundary sentence.** *"A drive that repairs what it finds destroys the evidence"* —
   `drives/2026-07-25/README.md:28`, stated there as an instruction the brief carried, and not
   repeated in any report since. It is the reason D1 was found, reported and fixed on its own slice
   rather than patched mid-drive.

Also preserved, though it was already durable: the count table's rule
(`SeedDriveFixture.php:135-137`) and the labels-are-identical-by-construction fact
(`DriveCastSeeder.php:111-139`) both live in source comments, which is the right place for them; the
skill quotes them rather than restating them, so they stay corrigible in one place.

## The gate

`bin/quality`, raw, one run. **15 steps**, not 14 — the frontend build was added as its own step.

```text
quality gate — base 8af8d3a

[1/15] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/15] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/15] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint: no changed PHP files
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/15] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/15] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/15] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/15] boundary lint (§17.2)
   ✓ boundary-lint
[8/15] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/15] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/15] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/15] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/15] sql-clock lint (no MySQL clock functions in raw SQL — two frames, one table)
   ✓ sql-clock-lint
[13/15] architecture tests (§17.1)
   ✓ arch
[14/15] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[15/15] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

Uncut. Base `8af8d3a`, which is `origin/staging` — correct for a branch cut from it. One run; it
passed first time, so there is no second run to report. Per ADR 0053 a single green is one
observation of a non-deterministic suite, and that caveat applies here as it does everywhere.

### Which steps actually examined this change, and which passed over it

This matters more than the result, because `.claude/` and `.md` are outside every gate in this
repository, and a green here means considerably less than a green on a code change. Verified by
reading the gates, not by assuming:

- **Step 3, `lint-changed`, looked at nothing of mine.** `bin/lint-changed.sh:42-50` sorts changed
  files into three lists: `*.php` for Pint, `resources/*.{ts,tsx,js,jsx,vue,css,json}` for Prettier,
  and `*.{ts,tsx,js,jsx}` for ESLint. **`.md` matches none of the three, and `.claude/` matches none
  of the three.** All six files in this commit fall through every `case`, so the step prints three
  "no changed files" lines — predicted from the source before the run, and visible in the pasted
  output above. That is a green meaning *"I did not look"*, which is the exact shape the script's own
  comment at `:34-41` was written to prevent for a different reason.
- **The seven lints scan `app/` only.** `ci-authz-lint.php:55`, `ci-money-lint.php:53`,
  `ci-runtime-zero-lint.php:64` and `ci-identifier-generation-lint.php:39` all iterate `$appDir`;
  `ci-boundary-lint.php` globs under `$root/app/Finance`; `ci-sql-clock-lint.php:108` pins
  `SCANNED_DIRS = ['app', 'database', 'routes', 'bin']`. None includes `docs/` or `.claude/`.
- **Larastan scans `app` only** — `phpstan.neon:11-12`.
- **Steps 4, 5, 13 and 15** (tsc ratchet, vite build, arch, suite + ratchet) run against a tree this
  commit did not alter. `grep -rn "\.claude" tests/ bin/` returns nothing: no test, no lint and no
  gate step reads the skills directory at all.
- **There is no markdown linter in this repository.** `package.json` has `format`/`format:check`
  scoped to `resources/`, and no `markdownlint` dependency. The MD warnings an editor shows on these
  files come from an IDE extension, not from a repo gate.

**So: zero of the fifteen steps examined any file in this commit.** The gate's result is evidence
that the change broke nothing, and it is not evidence that the change is correct. What stands behind
correctness here is the `path:LINE` citations above — every one of which was re-derived against the
files while writing this report, and three of which were wrong on the first pass and corrected
(`drive-environment.md:11-15` → `:13-14`, `SeedDriveFixture.php:136-138` → `:135-137`,
`.env.drive.example:39` → `:40`).

**That third correction was applied here and not to the skill**, which kept `:39` until `86eb511`.
The sentence above was true of this report and false of the file it described — see the next
section. Left standing as written, with this note, because a corrected sentence would hide the more
useful fact: re-deriving a citation and then writing it down in one place is not the same act, and
nothing in this repository catches the difference.

## Post-report verification pass

A verification pass over this report found **one uncited load-bearing quote** in the skill: the
"Drive the fixture, not the production copy" paragraph quoted the bank-accounts report's title with
no `path:LINE` beside it, while every other claim in that paragraph carried one — and that title is
the pivot of the ruling. Fixed in **`86eb511`**, cited to
`docs/handoff/reports/feat-finance-bank-accounts.md:200`; the quoted title was also corrected to
match that line exactly, the skill having lowercased its leading "The".

**The sweep run to verify that fix found a second defect, in the same commit's scope.** The skill
cited `.env.drive.example:39` for `SANCTUM_STATEFUL_DOMAINS`, which is on **line 40**. The "Which
steps examined this change" section below claims that citation was among three corrected on the
first pass — the correction had been applied **to this report and never to the skill**, so the
report asserted a fix that did not exist in the file it described. Also fixed in `86eb511`.

The sweep now returns **27** `path:LINE` tokens over the whole skill, every one resolving to a file
that exists with its highest cited line within that file's length, plus one bare continuation
(`:56-63`, belonging to `SeedDriveFixture.php`, 166 lines) that a `path:LINE` pattern does not
match. Zero missing, zero out of range.

## Not done

- **`finance-review` did not gain the manifest setup step** its own ticket asks for. Named above.
- **The skill has not been used yet.** It describes drives that happened; the first drive *run from
  it* has not occurred, so nothing here is proof that a reader following it end to end lands
  somewhere useful. The next screen commit is the test.
- **No `references/` directory.** `finance-execute` and `finance-review` both carry templates in
  `references/`; this skill carries its report shape inline instead, because it is five numbered
  items rather than a document structure. If drive reports start diverging, that is where a template
  goes.
- **I did not verify the drive environment by standing it up.** No `portal_drive` database was
  created and no browser was opened for this commit — the skill is derived from the source files and
  from six past drive reports, all cited. A claim in it that no past drive recorded and no source
  file states would be invention, and I have tried to include none; if one slipped through, it will
  be a sentence with no citation beside it.
