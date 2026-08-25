# Open findings — 24–25 August 2026

Everything here was found while doing other work and is real. None is fixed. Each carries its
evidence so nobody has to rediscover it.

Ordered by consequence, not by effort.

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

---

## 2. Result views are not recorded in the activity log

**Severity: high, and it is a disclosure problem before it is an engineering one.**

The system has an activity log with an admin screen. It did not record result views. So when the
guardian IDOR was found — 1039 enabled parent accounts, any of whom could read any child's records
— the question "was this ever exploited?" **could not be answered**, and the honest answer to
Brookstone is that it cannot be determined.

Under section 40 of the Nigeria Data Protection Act 2023, notification to the NDPC is triggered by
a breach *likely to pose a risk*, not by proof of access. Not knowing does not put you outside it.

**Log result and record views**, so the next time this question is asked it is a query rather than
a shrug.

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

---

## 7. The route-middleware baseline has drifted, and does not require entries for new guarded routes

**Severity: medium, and uncomfortably close to the class of gap the P0 fixes closed.**

`rbac:derive-map` wanted to add 356 lines across roughly 52 unrelated routes. More importantly, the
baseline test lets a **newly guarded route pass without a fixture entry** — so it is not tracking
what it appears to track.

---

## 8. Two terms in one session can both be `active`

**Severity: low, but the fix is cheap and there is a pattern for it.**

`CurrentTerm` step 1 is `->first()` with no ordering. The unique key is
`(academic_session_id, order)`, not status, so two active terms are representable and would resolve
arbitrarily. `orderBy('order')` would make a broken state resolve predictably, which is a plaster.

**Make it unrepresentable**, following `2026_08_19_100000_add_guardian_live_identity_uniqueness.php`
— a generated column that is the session id when the status is active and NULL otherwise, with a
unique index on it.

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
problem that predates them.

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
environment was deliberately not added at two call sites. **Revisit that around five.**
