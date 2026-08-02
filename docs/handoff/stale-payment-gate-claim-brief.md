# Brief — correct the stale "finance.access ALONE posts a payment" claim

Load `finance-method`, `finance-context`, `finance-execute` before you start.

**Base:** `staging` @ `0672ed8`
**Branch:** `docs/stale-payment-gate-claim`
**Shape:** 2 files, comment and prose only. Zero executable lines change. One commit.
**Review tier:** targeted, not full — see Part 3.

---

## The finding

`001fd1f` ("finance.payment.record gates both payment doors, ADR 0048 D1") put
`permission:finance.payment.record` on both payment POST routes. Verify:

- `routes/endpoints/finance.php:24-25` — invoice-addressed payment POST, gated
  `permission:finance.payment.record`.
- `routes/endpoints/finance.php:145-146` — student-addressed payment POST, same
  gate.

Three sites still assert the pre-`001fd1f` world as present fact:

1. `database/seeders/RbacSeeder.php:84` — inline comment on the role list:
   `// IA — activity-log only. NO finance.access: it alone records payments`
2. `database/seeders/RbacSeeder.php:375-383` — the `internal_auditor` block
   comment, which states `finance.access` "carries no further permission" on
   both routes and therefore "ALONE posts a payment", and rests the entire
   deferral rationale on that.
3. `docs/rbac/finance-seat-realignment.md:56-66` — the same claim in prose, and
   `:68-72` — a "Known pre-existing authority leak — NOT fixed here" section
   describing exactly the leak `001fd1f` closed.

**Environment it bites in:** everywhere, but it is a documentation defect, not a
runtime one. Nothing behaves wrongly. What is wrong is that the next person to
reason about whether `internal_auditor` can safely hold `finance.access` will
read a false premise stated with citations and line numbers, and conclude
correctly-by-accident or incorrectly. Both of the two most expensive defects on
this project started as a confident wrong sentence in a comment.

Note `:375-383` also cites `:143` as a route. `:143` is a line inside a comment
block; the route is `:145`. Fix that while you are there.

---

## What NOT to do

- **Do not touch `tests/Feature/Finance/PaymentRecordGateTest.php:3-6.** It
  makes the same claim and is **correct** — it is written in past tense
  ("Before it… so finance.access ALONE recorded a payment") describing the state
  the test exists to pin. Changing it would destroy the reason the test exists.
  This is the trap in this task.
- **Do not change any grant.** No entry in `grantsMap()`, no entry in the role
  list, no `PermissionEnum` value. The conclusion — IA holds no `finance.access`
  — is still correct. Only its stated reason is stale.
- **Do not re-open the deferral.** Whether IA should now get `finance.access` is
  a business question, not yours. See Part 2.
- **Do not run `rbac:sync`, with or without `--fresh`.** Nothing here changes
  what it would sync, and `--fresh` discards runtime matrix edits on a database
  that is a copy of production.
- **Do not delete the seat-realignment section.** History is why it is a doc.
  Mark it resolved; do not erase it.

---

## Part 1 — the seeder comments

`database/seeders/RbacSeeder.php`.

1. Line 84: rewrite the inline comment so the reason given is the current one.
   `finance.access` no longer alone records a payment; the current reason IA
   holds no `finance.access` is that `finance.access` is still an undifferentiated
   group gate covering read and non-payment write, so granting it would still
   give the control role more than V.
2. Lines 375-383: rewrite the block. It must state (a) that both payment doors
   are now gated on `finance.payment.record` as of `001fd1f`, with the current
   correct line numbers, (b) that IA therefore does **not** get payment
   capability from `finance.access` any more, and (c) the reason the deferral
   nonetheless stands, which is the read/act split, not the payment route.
3. Cite line numbers you have just read. Do not carry mine — re-derive them
   after your own edit shifts them.
4. Keep the block's existing register and width. It is a long explanatory
   comment; it should stay one.

If while writing (c) you conclude the deferral **no longer has a reason** —
that with the payment doors gated there is now no barrier to IA holding
`finance.access` — **stop and report that**. Do not grant it, and do not paper
over it with a rationale you had to invent. That is a finding worth more than
this task.

## Part 2 — the doc

`docs/rbac/finance-seat-realignment.md`.

5. `:56-66` — correct the claim the same way. Same three requirements.
6. `:68-72` — the "Known pre-existing authority leak — NOT fixed here" section.
   Do not delete it. Retitle it as resolved and add one line naming `001fd1f`
   as what closed it, so the record reads as a leak found, deferred, and later
   closed rather than as a live leak.
7. Line 65-66 carries a verified count ("38 auth-only/public routes and zero
   `/finance/*` routes"). **Re-derive it or drop it.** Do not carry it forward
   unchecked — if you cannot cheaply re-derive it, delete the sentence and say
   in your report that you deleted rather than re-verified.

## Part 3 — prove it

There is **no watched red available on this change** and I am not asking you to
manufacture one. Nothing here is executable; a comment cannot be regression-
tested. Say so plainly in your report rather than leaving the section blank.

What stands in for it:

8. `git diff --stat` — paste it. Expect two files, and expect the count of
   changed lines to be entirely within comment and markdown regions.
9. `git diff` on `RbacSeeder.php` — paste it raw. The reviewer must be able to
   see for themselves that no array entry moved. This is the load-bearing proof
   of this change.
10. Grep the tree for any remaining site repeating the claim, and paste the
    output:
    ```
    grep -rn "ALONE posts\|alone posts\|it alone records payments\|alone must not reach" \
      app database docs routes tests
    ```
    Expected after your change: only `PaymentRecordGateTest.php` (past tense,
    correct) and `routes/endpoints/finance.php:142` (present tense and correct —
    it describes the design intent, that `finance.access` alone must not reach
    the route, which is now enforced). If anything else appears, you missed a
    site.
11. `php artisan test --filter=PaymentRecordGateTest` and
    `--filter=RbacSeeder` (or the seeder's test, whatever it is named — find
    it, do not guess). Paste raw. Expect green, and expect green to be
    uninformative here — it proves you did not break the file, nothing more.
12. `bin/quality`. Paste the tail. If any step goes red, stop and report; do
    not fix an unrelated red inside this change.

**Review tier is targeted, not full.** The ladder puts "any change to roles,
permissions, grants or the seeder map" at full review — this change touches the
seeder *file* but not the *map*, and step 9 is what proves the distinction. If
your diff turns out to touch a map entry for any reason, the tier becomes full
and you say so.

## Stop and report

- The premise does not reproduce — the routes are not gated as I described.
- Part 1 item (c) has no honest answer.
- Any grant, enum or map entry changes for any reason.
- `bin/quality` goes red at any step.
- The grep at step 10 returns a site you cannot classify as correct or stale.

## Not in scope

The read/act split on `finance.access` itself (M5). The `SuperAdminAuthorityTest`
nondeterminism — known, do not chase it. Any other stale comment you find
outside these two files: raise it in the report under findings-not-fixed, do not
fix it here.

## When you are done

Follow `finance-execute`'s hand-off section exactly:

- Write the report to `docs/handoff/reports/docs-stale-payment-gate-claim.md`
  using `references/report-template.md`.
- Spawn the `finance-reviewer` subagent with **only** that path and the branch
  name. Nothing else — no summary, no "the risky part is X", no reassurance
  about what you already checked.
- Return its findings raw, alongside your report, unanswered.

Do not commit or push. Leave the branch for the project lead.
