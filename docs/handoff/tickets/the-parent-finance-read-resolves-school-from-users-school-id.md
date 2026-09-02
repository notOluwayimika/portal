# The parent finance read resolves the school from `users.school_id`, not the session — and no test could tell

**Status:** open. Found 2026-09-02 while writing the read-side isolation arm for step 5.
**Severity:** **fix** — ship-blocking for the pay screen's multi-school case, and **not** demonstrated
to be a production defect. Read "what is NOT established" before escalating.
**Bites in:** a guardian with children at more than one school.

## The measurement

Fixture: one user, a guardian row and a ward in **two** schools, both billed and released
identically. `ppf_hit()` calls `actingAs($user)->withSession(['school_id' => $school->id])`.

| `users.school_id` | asked for | returned |
|---|---|---|
| school A | school B | **ward A** |
| school A | school A | ward A |
| school B | school B | ward B |
| school B | school A | **ward B** |

The response tracks `users.school_id` in all four cells and never the session. Flipping one line of
the fixture flips the answer; changing the school being asked for does not.

`ActiveSchool::id()` reads the session first and falls back to `$user->school_id`
(`app/Support/ActiveSchool.php`). The observed behaviour is the fallback firing, which means
`$request->hasSession()` is false for this route in the harness — `/api/*` is stateful only through
`statefulApi()`, which decides on the request's origin.

## What is NOT established

**Whether a real browser request behaves this way.** The SPA sends an Origin the stateful middleware
recognises, so in production the session branch is expected to win and the switcher is expected to
work. **That was not measured, and it must be before this is called a live defect.** Presence of the
fallback is measured; reachability in production is not.

## Why it is a finding regardless

**No test in `ParentPortalFinanceReadTest` can tell the two mechanisms apart.** All 24 pre-existing
arms create their caller with `al_makeUser($school->id)` for the school they then read, so the
session value and the legacy fallback **always agree**. The isolation guarantee is unproven on this
endpoint — not weakly proven, *unproven* — and every arm reads as though it covered it.

That is the degrees-of-freedom collapse this repository has now recorded five times: the fixture
makes two candidate explanations indistinguishable, and the test's name stays true throughout.

CLAUDE.md is explicit that `users.school_id` must never be the source of context (Constitution 13),
with the remaining fallbacks baselined under ADR 0042. This is one of them, and it is the one the
parent-facing payment surface sits on.

## MEASURED SINCE: the consequence needed no browser, and it fails CLOSED

The first version of this ticket said only a drive could settle it. **That was wrong, and it was
wrong in the direction that costs most — it routed a cheap question to the expensive instrument.**
The CONSEQUENCE is reproducible in a test; only the CAUSE still needs a browser.

Two shapes, both now pinned by arms in `ParentPortalFinanceReadTest`:

| fixture | result |
|---|---|
| guardian row in **both** schools, `users.school_id` = A, asking for B | **200**, returns the ward in **A** — their OWN other child |
| guardian row **only in B**, `users.school_id` = A, asking for B | **403** — the permission team is set to A, where the `guardian` role was never assigned |

**Neither is a cross-guardian leak.** The second fails closed; the first shows the parent the wrong
one of their own children. So the original "if the session does not win, this becomes a **stop**"
branch is **narrower than written**: the worst measured outcome is a parent locked out of the portal,
or shown the wrong one of their own children — bad, and not a data breach.

Severity therefore stays **fix**, and the reason is measured rather than assumed.

## BUT THE DATE MOVES: before the PAY SCREEN ships, not merely before go-live

"Shown the wrong one of their own children" is a different fact on a payment surface than on a read
surface, and the read-side framing above under-describes it.

A parent who switches to school B is shown their child at school A and pays against **invoice A**.
Every guard passes and each is doing its job: `mayPay()` is
`isWardOf($user, $invoice->student_id)` and both children are their wards, so it is true; the invoice
belongs to the active school, so `SchoolScope` is satisfied. The payment completes, lands on the
wrong child's account, and `finance_payments` is append-only — the correction is a void production
cannot perform.

Nothing catches it server-side, because nothing is wrong server-side. The screen is internally
consistent: it displays child A and charges for child A. The parent would likely notice the name —
and **"would likely notice" is not a control**, which is the same objection this project applies to
every unenforced rule.

**A two-school family at resumption is precisely the population and precisely the week.**

So the deadline is **before the pay screen ships**, and the browser drive is the item that gates it:
it decides whether the defect is reachable in a real request at all, and that answer is needed before
any component is built on this endpoint. Ahead of the linear-history toggle in priority.

**One mitigation lands with the screen regardless of the ruling:** the confirmation names the
student, not only the invoice number (step-5 spec §4). It does not make the mistake impossible — it
makes it legible, which is all a confirmation can do.

## What it would take to answer it

1. **Measure the production path.** Drive the parent portal in a browser with a two-school guardian,
   switch schools, and read the network response. This is now the ONLY part that needs a browser —
   whether a real request carries a session the middleware honours. The consequence of it not doing
   so is measured above.
2. **If the session path does win in the browser**, the gap is the harness: the arm needs a request
   the stateful middleware accepts, and every existing arm should stop setting `users.school_id` to
   the school it reads, so the fallback cannot cover for a broken session path.
3. **If it does not**, a parent with children at two schools sees the wrong child's bills, and this
   becomes a **stop**.

## What was built now

`ParentPortalFinanceReadTest` gains an arm that asserts what IS measurable today — a guardian with
wards in two schools, reading the school their `users.school_id` names, gets only that school's ward
and not the other's. Its docblock states the limit and points here, and says explicitly **not** to
add a session assertion until this ticket is answered, because that would assert a mechanism the
request does not use and would read as coverage.

**Bite-proved** by inverting the school filter in `GuardianService::forUserInActiveSchool`. The
result is worth recording: the arm's two halves caught **different** mechanisms. The absence half
(`not->toContain(wardB)`) stayed satisfied — `SchoolScope` on `Student` filtered the other school's
ward out anyway — and the **presence** half (`toBe([wardA])`) is what went red, on an empty response.
An arm asserting only the absence would have passed the mutation completely.

## Whoever takes it

Unassigned. Step 1 is a browser drive, which is the project lead's environment.
