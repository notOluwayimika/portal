# TICKET — the drive fixture cannot reach a school with no bank account, and that is the state production ships in

**Status: OPEN.** Raised 2026-08-29 by the drive of the new-invoice modal's destination refusal
(`docs/handoff/drives/2026-08-29-new-invoice-destination/`), on branch
`docs/retire-pre-commit-2-destination-comments`.

## The gap

`resources/js/components/finance/new-invoice-modal.tsx` renders three states for the destination
catalog, told apart on purpose: loading, loaded-and-failed, and **loaded-and-empty**. The third is
the `accounts.length === 0` branch, and it renders no `<select>` at all — only an amber paragraph
telling the bursar that this school has no active bank account and that the invoice cannot be raised
until one exists.

**That branch has never rendered in a browser.** `SeedDriveFixture` gives School A **two** active
accounts (`ensureBankAccount` plus `ensureSecondBankAccount`, the second added for U10's allocation
mismatch axis, `SeedDriveFixture.php:177`) and School B **one** (`ensureBankAccount`, reached
through `plainInvoice`). The 2026-08-29 seed's own count table reads `Bank accounts | 2` and
`| 1`. There is no third school. So `accounts.length === 0` is unreachable on the fixture, and every
drive that has ever opened this modal has opened it onto a populated select.

The amber sentence in that branch was rewritten in this same commit to match what S11 commit 2 made
true. It is **derived, not driven** — from the 2026-08-29 drive's arm 1, where the 422
(`Line 1 — Select the account this charge is destined for. …`) and the non-write were both measured,
plus the fact that this branch renders no select, so there is nothing on it the bursar could choose.
The derivation is sound. It is still not a rendering, and the comment above the branch says so.

## Why it matters more than the usual instance

**This branch is production's day-one state.** Measured on production on **28 August 2026**:
`bank_accounts` is **0 for every school**. (That measurement is the project lead's, recorded here as
given; this ticket's author has no production access and did not re-derive it. Re-check before
acting on it.) The Bank accounts screen is where a school's first account is created, and until
somebody uses it, every school is in exactly the state the fixture cannot reach.

So the first bursar to open this modal after deploy lands on the one branch nobody has looked at —
and lands on it not as a corner case but as the default. The populated branch that every drive has
exercised is the state that arrives *later*.

## The pattern, and the inversion

The `finance-drive` skill records six prior instances of "the fixture could not reach the state the
screen is about": the empty academic slot and missing bank account U1 commit 1 added; the missing
students and guardians; U10's payments-with-a-remainder; U13/U14's decided documents; the
scholarships the seeder mentioned zero times; the BSS award pairs; and the money drive's
billed-subset discountable flags. This is the **seventh**.

It inverts the shape of all six. Every one of those was a fixture too **empty** to drive a screen —
a select with nothing behind it, a list with no rows, a cohort with nobody in it, and the fix was to
seed more. This one is a fixture too **healthy** to reach the state that ships. Seeding more is
exactly what put the branch out of reach: `ensureSecondBankAccount` was added for a good reason and
made the gap wider.

The general form worth carrying forward: **a fixture is built to make screens authorable, and the
states that are hardest to reach in it are the ones where nothing has been configured yet — which
is where every real deployment starts.** "Can this fixture author?" and "can this fixture reach
day one?" are different questions, and only the first one has ever been asked here.

## What closes it

A way to **drive** the empty-catalog branch. Either:

- a **third school** in the fixture with no bank account and a seat that can open this modal there;
  or
- a documented **deactivate-both** step — `deactivated_at` withdraws an account from choice
  (`BankAccount::isActive()`), and `selectableBankAccounts()` filters on `is_active`, so
  deactivating School B's single account through the Bank accounts screen should empty the catalog
  without deleting a row. `BankAccountController::reactivate()` clears `deactivated_at` again
  (`POST /v1/finance/bank-accounts/{uuid}/reactivate`), so the step is reversible and does not spend
  the fixture. Untried; if it works, write down the exact steps, because a drive procedure that has
  to be re-derived is one that gets skipped.

**Do not close it by reading the JSX.** Reading is what produced the sentence that is in the file
now, and reading is what this whole class of defect is invisible to — the opening-balance operator
screen's empty term select and blanked page both passed every reading and every test.

**A `vitest` render test does not close it either.** The runner exists now
(`docs/handoff/tickets/no-javascript-test-runner.md`, closed at `66cc22b`), and a test asserting
this branch renders its paragraph would be worth having on its own merits — but it would assert what
React does with `accounts = []`, not that a bursar signing into a school with no accounts sees a
screen they can act on. Those are the two claims this project keeps having to tell apart.

## Not in scope for the commit that raised this

The drive that found it was scoped to the destination refusal on a school that *has* accounts, and
a drive observes rather than fixes. Adding a third school to `DriveCastSeeder` moves the counts every
other drive reads against — `Students`, `Unplaceable`, the admission-number lists — and that is a
fixture change that belongs to whoever next needs this branch, argued in their own commit.
