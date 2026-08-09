# Implementation report — the school's day, not the server's

## Headline

**Done.** `App\Support\SchoolDay` answers "what day is it" in the school's timezone; nine business-date
call sites now ask it instead of the server clock; the boundary hour is proved in both directions.

Branch `feat/school-day-helper`, base `81dbd5e` (`origin/staging`, #229 merged).

`config/app.php` and `config/database.php` are **untouched**, as instructed.

## One correction to the brief, with evidence

The ticket's central claim did not survive measurement, and it changes what the ticket says.

> *"whereDate() compiles to `date(column) = ?`, which is SQL-side, so in production every row created
> between 18:30 and midnight UTC is filed under the following day by those queries."*

**That is not what happens.** The conversion is symmetric: MySQL renders a stored value back into the
same session zone it parsed it in, returning the identical string Laravel wrote.

```
row#179516
  MySQL DATE(created_at)      : 2026-08-09
  Laravel-side date of the row: 2026-08-09
  AGREE on the stored column? : YES
```

So the eight SQL-side date sites the hunt turned up are **safe**. The real asymmetry is between SQL's
*current time* and PHP's:

```
MySQL NOW() : 2026-08-09 20:46:52
PHP   now() : 2026-08-09 19:46:52
```

Which relocates the exposure to two sites nobody had named — `SubledgerPoster:117,120`, where
`NOW()` writes the account projection's own `created_at`/`updated_at` while every other timestamp in
the schema is written by PHP. Counts, including the zeros, are in the ticket.

## The survey, before the diff — and two sites the brief did not name

| Site | Kind |
|---|---|
| `RecordPaymentRequest:38` **`before_or_equal:today`** | **not in the brief** — Laravel resolves `today` through the app timezone too |
| `RecordPaymentRequest:42` `required_unless:received_at,…` | the 00:00–01:00 bug |
| `RecordAccountPaymentRequest:41` **`before_or_equal:today`** | **not in the brief** |
| `RecordAccountPaymentRequest:45` `required_unless:…` | |
| `GenerateInvoice:237` | charge's `effective_at` |
| `ApproveCreditNote:115` | credit note's `effective_at` |
| `DriveFinanceStates:44,51,66` | supplies a business date to a real Action |

`before_or_equal:today` matters as much as the reason condition: at 00:30 in Lagos it caps
`received_at` at the *server's* day, so a bursar entering their own current date is refused **for being
in the future** — before the reason rule is even reached.

### Deliberately untouched, so "untouched" is a decision

| Site | Why |
|---|---|
| `TeacherController:59`, `StudentController:150` | export **filenames**, not business dates |
| `SavedActivityFilterController:19` | a saved **filter** default over `activity_log`, whose stored values are in server terms; moving it would misalign the filter from the data it filters |

### Reconciliation — the diff checked against the survey

#229's lesson was that the survey *was* the oracle and nothing compared the change to it. So:

```
=== RECONCILIATION: diff vs survey ===
  Finance/Http/Requests/RecordPaymentRequest.php         survey=2 diff=2  OK
  Finance/Http/Requests/RecordAccountPaymentRequest.php  survey=2 diff=2  OK
  Finance/Actions/GenerateInvoice.php                    survey=1 diff=1  OK
  Finance/Actions/ApproveCreditNote.php                  survey=1 diff=1  OK
  Finance/Console/DriveFinanceStates.php                 survey=3 diff=3  OK

  TOTAL survey=9 diff=9  OK

=== residual server-clock business dates under app/Finance ===
  none

RECONCILIATION PASSED
```

It is **kept, not one-off**: the last arm in `SchoolDayTest` walks `app/Finance` and fails on any new
`now()->toDateString()`, so the next business date on the server clock is a red build rather than a
discovery a fortnight later.

## Where the timezone lives — a constant, agreeing with your lean

Three reasons, the first of which I think is decisive:

1. **A column would need a default, and the default would be this value.** The constant has to exist
   either way; a column adds a way to *change* it, not a way to *know* it.
2. `finance_school_settings` has one substantive column and no screen. A setting nobody can reach
   through a UI is another unreachable surface — four in a fortnight.
3. Both schools are Brookstone, in Nigeria. One timezone is not a simplification, it is the truth.

The flip condition is recorded: the first school outside West Africa makes it wrong, and the fix then
is a per-school column with this value as its default — the primitive arriving when its consumer
does.

## The boundary hour

At 23:30 UTC on the 9th — 00:30 on the 10th in Lagos:

```
tests=6 passed=6
  returns the SCHOOL day while the server is still on the previous one
  is the SAME instant read on two clocks, not a different moment
  agrees with the server for the other twenty-three hours
  the payment FormRequests take their day from the school, not the server  (invoice route, account route)
  leaves no business date in app/Finance taking its day from the server clock
```

Both directions are asserted — the server's answer *and* the school's — so the arm fails for the
reason it claims rather than by coincidence. And `SchoolDay::now()` is pinned to the same **instant**
as `now()`, because a helper that returned "now plus an hour" would pass a boundary test while
corrupting every other date.

### Watched red — the helper's timezone reverted, observed in the running program

```
SchoolDay::TIMEZONE (reflected from the loaded class) = UTC
  -> failed  passed=2 failed=4

* returns the SCHOOL day while the server is still on the previous one
  SchoolDay::today() agreed with the server instead of the school…
* the payment FormRequests take their day from the school, not the server
  RecordPaymentRequest caps received_at at the SERVER day
  (required|date|before_or_equal:2026-08-09), so at 00:30 in Lagos a bursar…
```

The FormRequest arm printing `before_or_equal:2026-08-09` **is** the bug, reproduced.

## Two traps hit while writing the test

**An unkeyed dataset of two class-strings produces ZERO tests and a bare `"failed"` with no message.**
Either class alone passes; two together silently register nothing. The file read as 4 passing arms
while the fifth had never run — a vacuous *red* that looks like a runner hiccup. Isolated by bisecting
(scalar pair: fine; one class: fine; two classes: zero) and fixed with keyed datasets, with the
finding written into the file so the next person does not re-derive it.

**`toContain($needle, $message)` treats the message as ANOTHER NEEDLE.** My failure explanation was
silently searched for inside the rule string. Same family as #222's negated-expectation trap — a
matcher quietly reinterpreting the thing you meant as an explanation. Rewritten as
`expect(str_contains(...))->toBeTrue($message)`.

## Recorded, not acted on

Production's MySQL is **+05:30**, dev's is **+01:00**, neither pinned by the repo; production's stored
epochs are 5.5 hours early. **The local copy is not id-aligned with production and is ~450 activity
rows stale**, so `activity_log` ids do not refer to the same events across the two — which retires
"check it against the copy" as a technique for anything id-addressed.

Full write-up: `docs/handoff/tickets/stored-epoch-offset.md`, including why correcting the data is
probably the wrong answer (`activity_log` is immutable, so no correction can include the audit trail,
and two inconsistent histories are worse than one consistently offset one).

## Proof

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance tests/Feature/Support
{"tool":"pest","result":"passed","tests":436,"passed":436,"assertions":1955}
```

### bin/quality — raw, unedited (ANSI stripped)

```
quality gate — base 81dbd5e

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 7 changed PHP file(s)
       Prettier: no changed frontend files
       ESLint: no changed JS/TS files
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

## Not done

- **No `APP_TIMEZONE` in `.env.example`.** That was STEP 2 of the withdrawn `config/app.php` change;
  with the app timezone left alone there is nothing for it to override.
- **The browser's `todayIso()` still uses the browser's local date**, not Lagos. It is a *pre-fill* the
  operator sees and can change, and the server is the authority that refuses wrong values — so a
  browser in another timezone gets a slightly wrong suggestion, not a wrong record. The 6c20d61
  workaround (rendering the reason field when the server reports the error) stays as defence. Making
  it Lagos-fixed would put the timezone constant in a second place, which is the hand-written-copy
  problem ticketed in #223.
- **`SubledgerPoster`'s two `NOW()` calls are not fixed** — ticketed, because changing how a
  projection stamps its own bookkeeping columns is a separate argued change.
