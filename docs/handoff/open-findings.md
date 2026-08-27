# Open findings — 24–25 August 2026, revised 27 August

Everything here was found while doing other work and is real. Each carries its evidence so nobody
has to rediscover it.

Ordered by consequence, not by effort.

**Where this file sits.** `docs/handoff/tickets/` is the repository's per-finding registry, one file
per finding, and it is the primary record — roughly a hundred of them. This file is the ordered
summary a reader starts from; where a ticket exists it is named, and the ticket is longer and more
current. **Eleven** were added on 27 August:

From the discount-base arc —

- `the-catalog-single-writer-arch-arm-cannot-see-a-raw-insert.md`
- `nothing-proves-the-discount-base-control-reaches-the-request.md`
- `a-caller-supplied-percent-base-survives-when-no-line-cites-a-policy.md`
- `award-student-discount-has-no-caller-and-therefore-no-gate.md`
- `half-the-boundary-lint-baseline-has-no-expiry-condition.md`
- `the-base-radios-have-no-machine-readable-value.md`
- `a-relative-reference-is-a-citation-with-nothing-behind-it.md`

From the `scholarships.kind` writer and its drive —

- `scholarship-controller-does-not-follow-the-house-request-pattern.md`
- `model-log-name-is-declared-as-a-static-property-spatie-never-reads.md`
- `a-permission-refusal-renders-a-dead-end.md`
- `an-error-handler-logs-the-raw-axios-error-to-the-console.md`

An earlier revision of this list said seven and omitted four. A count in a document is a fact that
rots the moment anyone adds to what it counts; if this list and `ls docs/handoff/tickets/` disagree,
the directory is right.

**`model-log-name-…` is the widest of the eleven and belongs beside finding 2 below.** Sixteen models
declare `protected static $logName = 'academics'`, six declare `'results'` and one `'setup'`, and
spatie reads none of them — `getLogNameToUse()` (`vendor/spatie/laravel-activitylog`) reads
`$this->activitylogOptions->logName`, set only by `LogOptions::useLogName()`. The production copy
holds no row under any of those three names; every model-trait entry the platform has written is in
`default`.

That is the same failure as finding 2, one layer along. Finding 2 is a log that **did not record**
something and so could not answer a question. This is a log that **records into a bucket nobody
queries**, so filtering the activity log by module returns an empty result *that reads like an
answer*. "Nothing was changed in academics" and "this filter has never matched anything" are the
same screen. Six models — `Teacher`, `Guardian`, `Student`, `Role`, `Permission` and now
`Scholarship` — use the working call and do land where they say, which is what makes the other
twenty-four look deliberate.

---

## 0. Staging is 44 commits and six migrations ahead of `main`

**Severity: this is the time-critical one, and it is operational rather than a defect. It is also
the gate on everything else: nothing in the BSS scholarship chain can happen on production until
this promotion does.**

Measured 27 August, and it moved from 36 to **44** in the course of a single day's work — the count
is a snapshot, not a fact. Re-measure before acting:

```
git rev-list --count origin/main..origin/staging
git diff --name-only origin/main origin/staging -- database/migrations
```

Six migrations have not run on production:

```
2026_08_21_110000_finance_allocation_not_over_payment_amount
2026_08_26_100000_add_kind_to_scholarships_table
2026_08_26_100001_bulk_invoice_run_rows_admit_sponsored
2026_08_26_110000_add_base_to_finance_discount_policies
2026_08_26_120000_create_finance_student_discount_awards
2026_08_26_130000_add_base_to_finance_discount_policy_changes
```

Five are the BSS/base-axis work and travel together. The sixth,
`2026_08_21_110000_finance_allocation_not_over_payment_amount`, is older and has been sitting — a
constraint that stops an allocation exceeding its payment, unshipped for six days.

Term 1 goes `active` on 5 September and the first bulk run follows it. Every one of these has to be
on production before that, and six migrations arriving in one promotion the week of cutover is the
shape that goes wrong.

**Promote in more than one step, and put the allocation constraint in its own.** It is unrelated to
the discount work and does not deserve to share its blast radius.

Corrected while measuring this: the current-term fallback fix (`c5fd93e`) and result-view logging
(`3d9e37f`) are **already on `main`**. They had been carried on this list as pending for two days and
were not.

---

## 1. The guardian role borrows four staff permissions — the root cause

**Severity: high. This is the cause of eleven separate holes, two of which were live in
production.**

The `guardian` role holds `result.view`, `student_curriculum.view`, `curriculum_subject.view` and
`student_status.view` (`RbacSeeder.php:323-332`). Every route behind those permissions was written
assuming the holder is staff. Two P0 fixes shipped on 25 August bolt ownership and reachability
guards onto that mismatch; they do not remove it.

A concrete symptom that survives both fixes: a teacher who is **also** a parent reaches the
whole-class results routes, where a teacher who is not a parent is refused at the permission gate.
Being a parent elevates a staff member.

**The fix is a parent-portal permission set** — `result.view.own` and siblings — with the staff
grants removed from the guardian role. It is the only change that stops the next one.

Deliberately deferred until after cutover: changing live authorisation for 1039 enabled accounts in
the week before launch is the wrong timing. That is a decision with a date on it, not a decision to
live with it.

---

## 2. Result views were not recorded in the activity log

**Severity: was high. The logging is built and on `main` (`3d9e37f`); the disclosure question it was
built to answer stays answered "cannot be determined" for the period before it.**

The system has an activity log with an admin screen. It did not record result views. So when the
guardian IDOR was found — 1039 enabled parent accounts, any of whom could read any child's records
— the question "was this ever exploited?" **could not be answered**, and the honest answer to
Brookstone is that it cannot be determined.

Under section 40 of the Nigeria Data Protection Act 2023, notification to the NDPC is triggered by
a breach *likely to pose a risk*, not by proof of access. Not knowing does not put you outside it.

The engineering half is closed. **The disclosure half is a decision Brookstone has to make, and it
has not been recorded anywhere that it was made.**

---

## 3. `bin/quality` has no per-step timeout, and `check()` swallows step output

**Severity: medium. Cost roughly three hours on 24–25 August.**

`check()` runs every step as `"$@" >/tmp/quality-step.log 2>&1`. The step heading prints before the
command, the tick or cross after — so a hung step shows the heading and then nothing, indefinitely,
with all its output redirected to a file nobody thinks to look at. There is no timeout on any step;
the only timeout discussion in the file is about Composer's 300s default on the Larastan step.

---

## 4. The test harness's 60-second process ceiling is indistinguishable from a regression

**Severity: medium. It is why the 9 August incident could not be diagnosed.**

`GrantsConvergenceLintTest` builds fixtures with real git plumbing under a fixed 60-second process
timeout. Under machine load — on 25 August, four `codegraph serve --mcp` instances indexing the
same repo alongside VS Code's language servers — a `git update-index` on one cacheinfo entry blew
that ceiling. The ratchet sees only test **names**, so a timeout and a real regression look
identical.

`bin/quality:305-315` already records the same shape on 9 August: PASS, then FAIL with 23 unrelated
permission/seed failures, then PASS on byte-identical code, unreproduced across eleven further
runs.

**Third datapoint, 27 August:** three runs of comparable work took **33s, 160s and 500s** on an
otherwise idle machine — a 15× spread, captured before re-running rather than after, and not
investigated. Machine load was not measured that time, which is the gap: on 25 August "otherwise
idle" was wrong and invisibly so. `ps aux | grep -c codegraph` before a run costs two seconds and is
the difference between a datapoint and a mystery.

The value of this entry is the **count**. One slow run is weather; three recorded instances of the
same shape is a property of the harness.

**Make a timeout say so distinctly, or raise the ceiling.** Both investigations were the same
investigation.

---

## 5. `resources/js/components/ui/*` is exempt from two gates at once

**Severity: medium.**

That path is excluded by **both** `eslint.config.js:117` and `.prettierignore:1`, for vendored
shadcn components. Any hand-written file placed there is invisible to both. Discovered when
`money-input.tsx` was moved out and immediately produced a real Prettier violation that had been
silently skipped — and `prettier --check` on an ignored path prints "All files formatted correctly"
without having looked.

**Audit that directory for anything not vendored, and consider listing vendored files rather than
exempting the whole path.**

---

## 6. `portal_testing` corrupts under concurrent test runs

**Severity: medium. Produces 233 failures that look like catastrophe.**

It is a single shared scratch schema. Running `--group=arch` concurrently with the full suite left
the schema half-built; every one of the 233 failures was a `QueryException` on a missing table or
column, not a behavioural assertion. `migrate:fresh` and a serial re-run cleared it.

The next person to hit this will reasonably believe they have broken something.

Related ticket: `quality-gate-is-not-safe-to-run-from-two-trees.md`.

---

## 7. The route-middleware baseline has drifted, and does not require entries for new guarded routes

**Severity: medium, and uncomfortably close to the class of gap the P0 fixes closed.**

`rbac:derive-map` wanted to add 356 lines across roughly 52 unrelated routes. More importantly, the
baseline test lets a **newly guarded route pass without a fixture entry** — so it is not tracking
what it appears to track.

---

## 8. Two terms in one session can both be `active`

**Severity: low, but the fix is cheap and there is a pattern for it.**

**Still fully open.** `CurrentTerm.php:116` remains `->where('status', ACTIVE)->first()` with no
ordering. The fix that shipped changed the FALLBACK chain — active, else the last `completed` by
`order`, else the first by `order` — and left the active query exactly as it was. A reader who knows
"the term resolution was fixed" will reasonably believe this went with it. It did not.

The unique keys are `(academic_session_id, slug)` and `(academic_session_id, order)`, neither of
which mentions status, so two active terms are representable and would resolve arbitrarily.

**Make it unrepresentable**, following `2026_08_19_100000_add_guardian_live_identity_uniqueness.php`
— a generated column that is the session id when the status is active and NULL otherwise, with a
unique index on it.

Ticket: `current-term-resolution-is-unordered.md`.

---

## 9. `fee-schedules.tsx:445` ships `amount_minor: minor ?? 0`

**Severity: unassessed — check before dismissing.**

A string that `nairaToMinor` rejected still travels as **zero**. It also records a per-row error, so
whether this is a real defect depends on whether that error blocks submission. Nobody has checked.

---

## 10. Two stale claims in `2026_08_17_100000`

**Severity: low.**

Its docblock still says the origin pairing admits "exactly these two" values — it now admits three
— and still says a `MESSAGE_TEXT` past 128 characters is silently truncated. Measured on 8.0.43, it
raises **1648/HY000** at fire time instead: the row is still refused, but by a different code, so a
caller classifying on 45000 would miss it. Better failure than documented, different failure.
5.7.23 unmeasured.

Migrations are history and should not be rewritten. **A one-line "superseded by" pointer is the
fix**, not an edit — but a reader arriving from `git blame` currently lands on two false claims.

Related: a baselined citation in `app/Support/CurrentTerm.php:61` still carries the old
bare-basename form. Silent today; the day someone edits that line they get a violation for a style
problem that predates them. Ticket:
`a-baselined-citation-can-go-stale-and-no-lint-notices.md`.

---

## 11. Two things unmeasured on production's MySQL

**Severity: low, but they are the quiet kind.**

Production is Percona **5.7.23**; everything here was measured on 8.0.43.

- `COLLATE utf8mb4_bin` inside a trigger body is *documented*, not measured, on 5.7 — flagged in
  `2026_08_17_100000`'s own docblock and inherited by the new `gateway` arm. If it does not take
  effect there, the guard admits `'Gateway'` while every other arm still bites, so it looks alive.
- The `MESSAGE_TEXT` 1648 behaviour above carries the same caveat.

**After the payment-origin migration runs on staging, attempt an insert with `origin = 'Gateway'`,
capital G, and confirm it is refused.** Two minutes, and it is the only way to know.

This now travels with finding 0: six migrations are about to meet 5.7.23 for the first time.

---

## 12. Five money inputs still unmasked

**Severity: low. Deliberate.**

`MoneyInput` is adopted at `issue-credit-note-modal` and `discount-policies`. Remaining:
`new-invoice-modal`, `record-payment-modal`, `allocate`, `fee-schedules`. **`new-invoice-modal` goes
last** — it is the only one whose state must be reshaped rather than its element swapped.

`opening-balances/import.tsx:543` is **excluded on purpose**: it posts the raw typed string and
`Money::fromNaira` parses it server-side. A `MoneyInput` emitting minor units would silently change
that contract.

No test renders `MoneyInput`. Its pure mappings are covered in the node environment; the DOM
environment was deliberately not added at two call sites — now three, with the discount base
control. **Revisit that around five**, and see
`nothing-proves-the-discount-base-control-reaches-the-request.md`, which is the same gap with money
behind it.
