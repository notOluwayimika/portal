# TICKET — the fee-schedule workflow is split across two seats, and neither holds both halves

**Status:** open, not implemented. Raised by `feat/fee-schedules-screen` (U1 commit 2) and
deliberately not fixed there: closing it is a **grants-map decision**, not a page change, and the
grants map is a deliberate artefact that belongs with the executive-director authority rulings
(`docs/finance/authority-matrix-decisions-2026-08-03.md`). It is the project lead's to rule.

## The workflow, and where it is cut

Authoring a fee schedule and publishing one are two abilities:

| Ability                              | Gates                                                                                                                                                     |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `finance.fee-schedule.manage`        | the screen `GET /finance/fee-schedules` (`routes/web.php`), the nav item, and `store` / `editDraft` / `supersede` (`routes/endpoints/finance.php:92-101`) |
| `finance.fee-schedule.change.submit` | `POST /api/v1/finance/fee-schedule-changes` (`routes/endpoints/finance.php:117-118`) — proposing a publish or a retire                                    |

**Exactly one seeded role holds both.** From `RbacSeeder::grantsMap()`:

| Role                           | `…MANAGE`  | `…CHANGE_SUBMIT` | Consequence                                                                                                                  |
| ------------------------------ | ---------- | ---------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `accounts_officer` (`:371`)    | `:381`     | `:389`           | complete — authors and proposes                                                                                              |
| `admin` (`:210`)               | `:235`     | **absent**       | gets the nav item, the screen, Create, Edit and Re-price — **and no way to publish or retire what it authors**               |
| `accounts_supervisor` (`:454`) | **absent** | `:456`           | can propose a publish — **and cannot reach the screen a schedule is proposed from**, nor see the draft it would be proposing |

Derive it rather than trusting the table: the arms in
`tests/Feature/Finance/FeeSchedulesScreenTest.php` read `RbacSeeder::grantsMap()` directly and one of
them fails if the `admin` half ever changes.

## It is ONE question, not two

The two rows are mirror images of a single omission, and fixing either alone leaves the other. Stated
together because a ticket for `admin` alone invites "grant `admin` the submit ability", which closes
half of it and makes the `accounts_supervisor` half harder to see.

- `admin` is a **maker with no proposal**: it can price a term and then has to find somebody else to
  send it to the ED.
- `accounts_supervisor` is a **proposal with no maker**: it holds the submit ability for a document it
  cannot open, in a UI that will never show it one.

## What this commit already did, and why it is not the fix

U1 commit 2 gates the Submit-for-approval and Retire buttons on
`can('finance.fee-schedule.change.submit')`, so the `admin` seat is no longer offered a control that
403s on click. That is an improvement on the symptom and **not** a resolution: the seat still reaches
a screen whose workflow it cannot complete, and `accounts_supervisor` is untouched by it — a page
gate cannot grant an ability, and `accounts_supervisor` cannot reach the page to be gated in the
first place.

## The rulings available

Not a recommendation — the options, so the decision is a choice rather than a discovery:

1. **Grant `admin` `…CHANGE_SUBMIT`.** Makes `admin` a finance maker seat. Check it against ADR 0040
   and the duty-separation pairs first: `admin` holds no finance checker ability today, so this does
   not create a both-sides holder, but it does widen who may put a price in front of the ED.
2. **Drop `admin` from the page's gate** — i.e. remove `…MANAGE` from `admin`, leaving fee-schedule
   authorship to `accounts_officer`. The narrower reading of "admin is not a finance seat", and the
   one most consistent with `admin` already holding neither credit-note ability (`RbacSeeder.php:237-240`).
3. **Grant `accounts_supervisor` `…MANAGE`.** Closes the mirror half; makes the supervisor able to
   read and author, which may or may not be what "maker-and-viewer seat" (`:450-453`) was meant to
   mean.
4. **Leave both and say why**, in the authority-matrix decisions file — e.g. if `admin` is expected
   to author and hand off in practice. A recorded decision closes this ticket as legitimately as a
   grant does; an unrecorded one leaves the next reader re-deriving it.

Whichever is chosen, the grant side has a mechanical consequence worth knowing before the edit:
`rbac:sync` grants a pre-existing permission to an **existing** role only through a convergence
migration — `bin/ci-grants-convergence-lint.php` (gate step 8) fails the commit that edits
`grantsMap()` without one. See `docs/runbooks/rbac-grants-reconciliation.md`.

## Related

- `docs/handoff/reports/feat-fee-schedules-screen.md` §2a — where the `admin` half was found, with the
  button gate that followed from it.
- `docs/finance/authority-matrix-decisions-2026-08-03.md` — where the ED-role authority decisions live
  and where this ruling belongs.
- `docs/finance/segregation-of-duties.md` — the maker/checker pairs this must not disturb.
