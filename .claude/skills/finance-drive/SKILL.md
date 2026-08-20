---
name: finance-drive
description: How this project drives a screen in a real browser — the throwaway drive instance, the fixture and its count table, the seats and what each one proves, checking isolation by id rather than by label, the friction that has already cost sessions, and what the drive report must contain. Load this whenever a brief says to drive a screen, whenever you are about to write a brief that asks for one, whenever you are asked to look at a page in the running app, and before you claim a screen works. It replaces the DRIVE section that briefs used to carry.
---

# Driving a screen

A brief no longer specifies how to drive. It says **which screen, which seats, and
what to look at there** — the procedure is this file, because the procedure has
been the same for months and rediscovering it per drive is what made every drive
expensive.

## What a drive is for

The acceptance suite proves the HTTP stack and is **structurally blind to
rendering**. A 200 with the right list, a 200 with an empty list, and a 200
rendering an error where a list should be are the same assertion
(`docs/finance/drive-environment.md:8-10`). Everything in the class the suite
cannot see is what you are here for: a select rendering empty because the fixture
seeds nothing behind it; a label that says the wrong thing; a button that 403s for
a seat no test ever acts as; a page that loads and cannot be submitted.

Two pieces of evidence, both from this repository, both green in every test:

**The opening-balance operator screen shipped two defects the drive caught on its
first run** (`docs/handoff/reports/feat-finance-ob-operator-screen.md:200-215`).
`routes/web.php` bound `ActiveSchool::getOrFail()` — a **School model** — into
`where('school_id', …)`, where the int was wanted. It matched nothing, so the
term select was empty and the form could never be submitted; the page returned
200 and every assertion passed, because the assertions asserted that the screen
*renders*. Separately, `store` answered without `rejected_rows` while the page
immediately read `active.rejected_rows.length`, and the browser console showed
`Cannot read properties of undefined (reading 'length')` on a blanked screen.
That commit was the fourth in its feature to defer the drive and the first to run
it. It paid on the first run.

**U1's fee-schedules screen hit the same class one layer out — in the fixture.**
`DriveCastSeeder` seeded no academic session, no term and no class level
(`DriveCastSeeder.php:127-131` records this), and `SeedDriveFixture` gave School B
only a `plainInvoice`, which records no payment, so School B had **no bank
account** while `school-b@drive.test` holds the ability to open the author screen
(`docs/handoff/reports/feat-fee-schedules-data-surface.md:437-450`). A drive would
have opened onto three empty selects and authored nothing — and **no test can see
this fixture at all**: the seed command refuses outside `APP_ENV=drive`
(`SeedDriveFixture.php:49-54`) and `phpunit.xml:29` pins the suite to
`APP_ENV=testing`, so the suite could not run it if it tried. Both were
caught by *reading the seeder* before the drive rather than by the drive itself,
which is the entire reason the next section exists and comes before you open a
browser.

## Stand the environment up

The drive runs on a **second, throwaway instance** — `APP_ENV=drive`, its own
database, port 8001 — never on your dev database and never on a production copy.
Full setup, including the one-time `.env.drive`, is
[`docs/finance/drive-environment.md`](../../../docs/finance/drive-environment.md);
do not restate it in a brief, link it.

```bash
pnpm install && pnpm run build                         # REQUIRED — see below, before the browser
APP_ENV=drive php artisan finance:seed-drive-fixture   # migrate:fresh + seed, idempotent
APP_ENV=drive php artisan serve --port=8001
```

**The build is not optional and it comes first.** `public/build/` is gitignored, so a fresh clone has
no manifest, and every Inertia page — which is every finance screen — answers
`ViteManifestNotFoundException: Vite manifest not found at: …/public/build/manifest.json`. A manifest
that predates the page you are driving gets the sibling
`ViteException: Unable to locate file in Vite manifest: …`. `pnpm run dev` left running is the
alternative; it serves the assets from the dev server instead, which is what
`docs/finance/drive-environment.md:51` assumes. Either way this happens **before** you seed, because
the failure it prevents looks like a broken feature and arrives at the moment you open a page.
**Do not fabricate a `manifest.json`** — someone did, to get past exactly this; it satisfies the
lookup with a file that was never compiled, so the guarantee that the bundle *builds* is skipped
while everything looks fine. The whole argument, and the alternatives rejected, is in
[`docs/handoff/tickets/fresh-clone-review-needs-a-built-manifest.md`](../../../docs/handoff/tickets/fresh-clone-review-needs-a-built-manifest.md).

**`pnpm`, not `npm`.** The committed lockfile is `pnpm-lock.yaml` (there is no `package-lock.json`),
and `bin/quality` shells `pnpm` throughout (`bin/quality:195`). Two committed files still say `npm`
— `composer.json:54-61`'s `setup` script and `docs/finance/drive-environment.md:51` — and both work,
since they resolve the same `package.json` scripts. Prefer `pnpm` so you are installing against the
lockfile the gate installs against.

**There is no committed drive script.** Nothing under `git ls-files` matches puppeteer, playwright or
a drive harness, and `package.json` declares neither. Where this file says "the drive script", it
means the throwaway script you write for your own drive and do not commit — past drives used
`puppeteer-core` against system Chrome (`docs/handoff/drives/2026-07-25/README.md:3-4`) and
Playwright-driven Chromium (`docs/handoff/reports/feat-finance-ob-operator-screen.md:191-193`). Both
are fine. Neither is provided for you, and whichever you pick, install it **outside the repository**
(see Friction).

The command **refuses** to run unless `APP_ENV` is exactly `drive`
(`SeedDriveFixture.php:49-54`) **and** the database name contains a `drive` token
(`SeedDriveFixture.php:44`, `:56-63`) — an allowlist, not a denylist, so a name
nobody anticipated (`finance_demo`, `school_uat`) is refused rather than wiped. It
`migrate:fresh`-es, which is why the guards are structural. Both must be satisfied
honestly; neither is a thing to route around.

Every state in the fixture is produced by **executing the real Actions**, never by
writing rows, so nothing you see is a state the system cannot reach —
`finance:reconcile-accounts` runs clean on the result
(`docs/finance/drive-environment.md:17-19`).

**Drive the fixture, not the production copy.** Past drives disagreed on this.
Three ran against the local production copy — the sidebar
(`docs/handoff/reports/feat-finance-sidebar-section.md:167-170`), the fail-closed
RBAC change (`docs/handoff/reports/feat-rbac-fail-closed-finance.md:442-447`) and
the opening-balance operator screen
(`docs/handoff/reports/feat-finance-ob-operator-screen.md:189-198`). The
bank-accounts report is titled *"The browser drive — portal_drive, never the
production copy"* (`docs/handoff/reports/feat-finance-bank-accounts.md:200`), and
both drives since have opened by seeding the fixture
(`docs/handoff/reports/feat-fee-schedules-screen.md:246`,
`docs/handoff/reports/feat-discount-policies-page.md:343`).

The copy-based drives are what settled it. One left six `DRIVE-*` batches — *"1
validated and 5 rejected"* — behind in `school#1`
(`docs/handoff/reports/feat-finance-ob-operator-screen.md:283`), along with two
minted users that are still there
(`docs/handoff/reports/feat-finance-ob-operator-screen.md:297-298`). One could not
drive the `super_admin` seat at all, because logging in as a real user needs a
**credential write on a production copy** and the environment refused it
(`docs/handoff/reports/feat-rbac-fail-closed-finance.md:469-476`). And the
opening-balance **approve** has never been driven anywhere, under a condition set
down before the fixture era: *"Approve stays undriven until there is a database we
are willing to spend"*, because the first approval consumes that school's single
posting slot permanently, with no un-post, no delete and no move
(`docs/handoff/reports/feat-finance-ob-decision-surface.md:653-655`).

That phrase is the argument for the fixture, and it is worth reading in its
original frame: it was written about a **production copy**, where the price of an
irreversible action is real. A throwaway database is one you are willing to spend
by construction.

## Check the fixture before you drive anything

The seed command prints a **count table** of the authoring slot per school
(`SeedDriveFixture.php:195-202`) — **eleven columns**: academic sessions, terms,
class levels, bank accounts, discount policies, payments split by `origin`, and, as
of U6 commit 4, active fee schedules, the cohort at the fixture's pricing slot and
the unplaceable count. It is counted from the database through `DB::table`, through
the Finance side's own scoped counters and — for the last two — through the ACL
PORT, deliberately **not** from the seeder's own variables, which would only ever
report what the seeder intended (`SeedDriveFixture.php:147-192`).

**THE COLUMN LIST GROWS, AND THAT IS THIS FILE'S OWN RULE BEING OBEYED.** It was
eight columns until a bulk-run drive needed to know whether a cohort and a price
list existed at all; that drive found the fixture placed EVERY episode at null
coordinates and seeded no schedule, so a run would have billed nobody on a fixture
that looked full. The columns landed in the same commit as the fixture change.
**When your screen depends on something the table does not count, add the column
before you open a browser** — and update the enumeration in this paragraph with it,
which is the step U6 commit 4 missed and a cold review caught.

**Nothing in this repository can execute that table.** `SeedDriveFixture` refuses
outside `APP_ENV=drive` (`:49-54`) and `phpunit.xml:29` pins the suite to
`APP_ENV=testing`, so no test reads a single one of these columns. Its only proof
is the output you paste into your report, which is the reason this section asks
for it verbatim rather than summarised.

Read it first, every time. The rule the table was built to serve, in the source's
own words: **"Zero in any column means the screen cannot author anything"**
(`SeedDriveFixture.php:150-152`). What follows from that is mine and not the
source's: **the drive is then worthless before it starts**, so this is a check you
run and act on, not one you record.

**ONE COLUMN IS EXEMPT AND IS ZERO BY CONSTRUCTION: `Payments (migrated)`.** Do not
read it as an abort. `origin = 'migrated'` has exactly ONE writer in the codebase —
`PostOpeningBalanceBatch` — reachable only by approving a submitted opening-balance
batch, and nothing in this fixture stages one (`DriveFinanceStates` exposes no
opening-balance state method). So **every** fresh fixture shows a zero there, for
every screen, forever, and a rule that said "stop" would fire on every future drive
of every screen. What the zero actually means is narrower and still worth printing:
*the migrated-payment cases of whatever you are driving cannot be reached from the
seeded state.* If your screen has one — U11's receipt refusal is the first — you
either walk the real cutover yourself (upload and submit as `maker@drive.test`,
approve as `checker@drive.test`; the receipt drive did exactly this and it takes
about ten minutes) or you say plainly in your report that the case was proven by
test only and never rendered. **Do not claim a rendering you did not see.**

Every other column keeps the original rule: a zero is a stop. It is also the exact
failure U1 was written to prevent, and the reason the bank-accounts and
discount-policies columns were added beside the academic three as each new screen
arrived. If your screen depends on
something the table does not count, the table needs a column before your drive
needs a browser — and that is a change to the fixture, in your commit, argued.

Paste the table into your report verbatim. It is the fixture's own claim about
itself, and it is the cheapest evidence in the whole drive.

As of U6 commit 4 it prints eleven columns and looks like this on a fresh seed:

```
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+------------------+----------------+-------------+
| School       | Academic sessions | Terms | Class levels | Bank accounts | Discount policies | Payments (portal) | Payments (migrated) | Active schedules | Cohort at slot | Unplaceable |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+------------------+----------------+-------------+
| A (school#1) | 2                 | 2     | 2            | 1             | 1                 | 3                 | 0                   | 1                | 2              | 7           |
| B (school#2) | 2                 | 2     | 2            | 1             | 1                 | 0                 | 0                   | 1                | 2              | 1           |
+--------------+-------------------+-------+--------------+---------------+-------------------+-------------------+---------------------+------------------+----------------+-------------+
```

Read the three right-hand columns together, because they only mean something as a
set. **`Cohort at slot`** is the fixture's placed, unbilled students at (current
term, JSS 1) — the only coordinates anything is enrolled at — so a run has somebody
to bill. **`Active schedules`** is filtered to `active`, the only status a run may
bill from, so a catalog of drafts cannot read as a usable price list.
**`Unplaceable`** is School-wide and is deliberately NON-zero: those are the
pre-existing episodes with no term and no class level, and a fixture where every
episode was placeable could not render the one list on a run report that somebody
has to act on. School A's 7 is fewer than its 8 unplaced episodes because one
student holds two, and the ACL port takes at most one episode per student.

## The seats, and what each one proves

Read `DriveCastSeeder::seedCast()` (`DriveCastSeeder.php:232-258`) for the current
list — it changes, and it has changed. The password is the constant
`DriveCastSeeder::PASSWORD` (`DriveCastSeeder.php:37`); read it there rather than
from a brief, since a brief that pastes it goes stale silently.

| Seat | Holds | What it proves |
| --- | --- | --- |
| `maker@drive.test` | `accounts_officer`, School A | The flow works end to end: the authoring screens open, the selects are populated, a thing can be created, edited and submitted. |
| `checker@drive.test` | `executive_director`, School A | The approvals queue, and therefore every outcome a proposal has. The ED holds **every** finance checker side — fee-schedule, discount-policy, credit-note, invoice-void and opening-balance, approve and reject (`RbacSeeder.php:413-444`), the placement rule being *"a new finance checker ability lands on ED, full stop"* (`RbacSeeder.php:427-432`). |
| `void-checker@drive.test` | a dedicated role holding **only** `finance.access` and the two void-request abilities | A **partial** checker is not locked out. This seat exists because the first drive found exactly that: `/finance/approvals` was gated on one permission, so the unified queue's per-feed 403-tolerance never executed and a void-only checker got a full-page 403 (`docs/handoff/drives/2026-07-25/README.md:30-46`). |
| `super@drive.test` | `super_admin`, no finance grant | The bypass exclusion — a super admin does not approve: *"checker abilities are never bypassed — ADR 0040/0045"* (`docs/handoff/drives/2026-07-25/README.md:92`). Bypass is *authorization*, never *isolation*. |
| `school-b@drive.test` | `accounts_officer`, School B | Isolation. See the next section; this is the seat that section is about. |

`checker@drive.test` held `accounts_supervisor` until 2026-08-04 and now holds
`executive_director` (`DriveCastSeeder.php:235-237`). If a brief you are working
names a role for a seat, check the seeder rather than the brief.

**The maker and the checker are two accounts and cannot be one.** Grant-time
segregation of duties refuses to give one user both sides of a Finance pair —
`User::assignRole` throws before the write, with no flag, no `--force` and no
super-admin shortcut, because the guard lives in the model below every path
(`docs/finance/drive-environment.md:69-74`). You cannot add both roles to a single
login to click through the whole flow yourself. Sign in as each in turn; a second
browser profile or a private window keeps both sessions live.

## Isolation is checked by id, never by label

`seedAcademicSlot()` runs identically for both schools
(`DriveCastSeeder.php:146-230`): each gets a **current** session named `2026/2027`
holding a term named `First Term`, a **non-current** session named `2025/2026`
holding a term named `Third Term`, class levels `JSS 1` and `JSS 2`, and an arm on
`JSS 1` only. **Every one of those labels is an identical string across the two
schools, by construction.** A screen showing "First Term" therefore proves nothing
whatsoever about which school's term it is.

Two of those are there for a reason worth knowing before you read a select. The
**second, non-current session** exists so a screen that DEFAULTS a term can be
caught defaulting it wrongly: its term is `active` too, sits at a higher `order`,
and has a higher id, so "newest", "highest order", "first in the list" and "any
active term" all land on the wrong one and only the current-session filter lands on
the right one. And `JSS 2` is left **unarmed** on purpose, so there is a class level
whose cohort is genuinely empty — a real state, and one a screen must be able to
report without looking broken.

What proves it is the **ids**, disjoint across the two seats. U1's drive is the
recorded method
(`docs/handoff/reports/feat-fee-schedules-screen.md:258-303`):

Copied from that report unaltered — the seat headings are its own
(`:258`, `:294`), and the option lines are its `MODAL` lines verbatim
(`:266-268`, `:298-300`). Nothing here is abridged; read it as a sample of what
your own drive log should look like.

```
Seat 1 — `maker@drive.test` (accounts_officer, school#1)
  MODAL term options   (1): ["1|2026/2027 — First Term"]
  MODAL level options  (2): ["1|JSS 1","2|JSS 2"]
  MODAL account options(2): ["|Choose an account…","a27ab5dc-57c5-43f4-a08b-f192871f6eb9|Drive account · Drive Bank"]

Seat 2 — `school-b@drive.test` (isolation, school#2)
  MODAL term options   (1): ["2|2026/2027 — First Term"]
  MODAL level options  (2): ["3|JSS 1","4|JSS 2"]
  MODAL account options(2): ["|Choose an account…","a27ab5dc-58b0-4fba-96e9-504f192c0530|Drive account · Drive Bank"]
```

Term `1` against term `2`; levels `1,2` against `3,4`; two different account
uuids — and three label strings that match character for character.

**Look at the placeholder option.** Its value is the **empty string** — `"|Choose
an account…"` is a `|` with nothing to its left, and the ellipsis belongs to the
*label*, on the far side of the separator. That is what an unselected select looks
like, and it is the reason this section says read values and not text: a reader
skimming the label would see three dots and a sentence, and a script comparing
labels would find both schools identical. Never abbreviate a value when you paste
a log — an elision in the value column is indistinguishable from a value.

Read the option **values** out of the DOM, not the option text, and put both
seats' lists side by side in the report so the disjointness is visible rather than
asserted. Then check the second half: School A's newly authored row must be
**absent** from School B's list.

## Friction, already paid for

Each of these cost someone a session. None of them is a defect in the change you
are driving.

**The assets prerequisite is in "Stand the environment up", not here** — it fires
before you reach any of this, so it is stated where you will read it in time.

**`/dashboard` 403s for the finance seats, and bounces them to `/login`.**
`maker@drive.test` and `school-b@drive.test` sign in successfully and are then
refused on `GET /dashboard`. Every finance page is reachable directly, so it does
not block a drive — but it is the first screen a first-time driver sees, and it
looks exactly like a broken login. **Pre-existing**, filed as a ticket by the drive
that observed it, and unrelated to whatever you are driving
(`docs/handoff/reports/feat-discount-policies-page.md:456-460`). Navigate straight
to `/finance`.

**`:8001` must be in `SANCTUM_STATEFUL_DOMAINS`**, or every SPA call to
`/api/v1/finance/*` 401s and every statement renders "Could not load the
statement". It is already in the committed `.env.drive.example:40`; if you built
your `.env.drive` from something else, this is the first thing you will hit
(`docs/handoff/drives/2026-07-25/README.md:77-83`).

**`php artisan serve` is single-threaded**, and the SPA can lose the CSRF race on
the very first paint. Have the drive script reload once on the error state rather
than reporting the error state (same source).

**Measure after the redirect settles.** A drive once reported
`sidebar entry present: false` because it counted links immediately after login,
while the page title still read "Log in" and no shell had rendered — a
measurement artifact that would have been filed as a phantom defect
(`docs/handoff/reports/feat-finance-bank-accounts.md:224-230`). Assert the page
you think you are on before you read anything off it.

**Install the browser outside the repository.** Puppeteer/Playwright's own
download must not land in `node_modules`; the drive that got this right recorded
it as a property of the run
(`docs/handoff/reports/feat-finance-ob-operator-screen.md:191-193`). A mutated
`node_modules` is how a `tsc` baseline once got calibrated against a corrupted
tree — `CLAUDE.md:72` names *"the corrupt-`node_modules` tsc lie"* as its own class
of failure, and this is the same directory.

**`page.request.get()` on an API route returns 401** under Playwright: no
`Referer`, so Sanctum does not treat the request as stateful. That is a harness
artifact and not a defect — click the real button
(`docs/handoff/reports/feat-finance-ob-operator-screen.md:299-301`).

## What to report

Screenshots go in `docs/handoff/drives/<date>-<screen>/`, named so a reader knows
what each one shows without opening it — `maker-03-inline-amount-error.png`,
`isolation-01-school-b-list.png`. The drive section of your implementation report
carries:

1. **The fixture count table**, pasted from the command.
2. **What the selects actually contained — by count and by value.** Not "the page
   loaded", not "the select was populated". The raw lines your script read out of
   the DOM, uncut, exactly as U1 and U2 pasted them. A summary of what a select
   contained is a claim about what it contained.
3. **What each observation establishes**, in order, one line each — including the
   arithmetic where a total is on screen. "`250000.50 + 12000 = 262000.50`,
   rendered `₦262,000.50`; nothing in the page computed it" is a claim a reader
   can check.
4. **Both seats side by side** for the isolation check, ids visible.
5. **What was NOT driven, and why.** On every drive so far this has been the
   lifecycle states the fixture cannot reach: the retire and supersede paths that
   need an *active* schedule, which only the ED's approval creates; a *rejected*
   proposal, because the ED approved everything; and **anything opening-balance**,
   for a blunter reason — the fixture seeds no opening-balance state whatsoever.
   `DriveFinanceStates` exposes **sixteen** public methods besides its constructor,
   spanning `ensureBankAccount` to `plainInvoice`
   (`app/Finance/Console/DriveFinanceStates.php:76-351`), and not one of them stages
   an opening-balance batch — four of them (`bankAccountCount`,
   `discountPolicyCount`, `paymentCount`, `activeFeeScheduleCount`) are the count
   table's readers rather than state at all; `SeedDriveFixture` and `DriveCastSeeder`
   between them mention opening balances once, in a comment
   (`DriveCastSeeder.php:130`). So there
   is nothing on this fixture to approve, and the "database we are willing to
   spend" condition is not what is stopping you — that condition was written about
   a production copy and does not transfer to a database that is thrown away.
   **U11's drive proved the corollary:** you can stage one yourself through the real
   screens in about ten minutes, and the opening-balance **approve** — undriven
   anywhere until then — has now been driven on the fixture, irreversibly spending
   that school's single posting slot exactly as intended
   (`docs/handoff/reports/feat-finance-payment-receipt.md` § 7).
   Name what you skipped. This is the largest untested-by-eye area of most commits
   and it should be stated rather than left to be discovered.

Everything here is under the same privacy rule as the rest of the project:
`user#<id>`, `school#<id>`, counts and structure. The drive of the
opening-balance findings screen is the demonstration that the rule survives a
*rendered page* — *"line numbers, admission numbers and both sides of the failed
check; no name anywhere"*
(`docs/handoff/reports/feat-finance-ob-operator-screen.md:238-240`).

## A drive observes; it does not fix

**A drive that repairs what it finds destroys the evidence**
(`docs/handoff/drives/2026-07-25/README.md:28`). What you find goes in the report
with what you saw and where — and the decision about it is the project lead's, not
yours.

Two exceptions, both narrow and both already exercised. If the **fixture** cannot
reach the state your brief told you to drive, fixing the fixture is in scope: it
is a precondition of the drive, not a finding from it. U1 commit 1 is the
precedent — it added the academic slot and the per-school bank account to the
seeder so that commit 2's drive would not open onto empty selects
(`docs/handoff/reports/feat-fee-schedules-data-surface.md:437-450`,
`DriveCastSeeder.php:127-131`). And a **drive-environment config** change — the
`:8001` Sanctum entry, added to `.env.drive.example` by the drive that hit it
(`docs/handoff/drives/2026-07-25/README.md:77-83`) — is config, not the feature.
Everything else, including the obvious one-line fix sitting in front of you, is
reported and left alone.
