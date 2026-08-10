# Implementation report — the bank-account foreign keys (S6/S11, commit 2 of 2)

## Headline

Money now says which account it landed in, and a charge says which account it is destined for.

Branch `feat/finance-bank-account-fks`, base `2d5428e` (`origin/staging`, #232 merged).

**Full-review tier** — money, a migration with a CHECK and two foreign keys, and a required field on
the only money-in path in the product.

## Emptiness, counted per table rather than as a group

| Table | Rows | NOT NULL / CHECK available? |
|---|---|---|
| `finance_payments` | **0** | yes |
| `finance_fee_items` | **0** | yes |
| `finance_invoice_lines` | **0** | yes (not used — see below) |

The fuse's stop condition did not fire. Also worth recording: `finance_bank_accounts` **did not exist
in the dev copy** — commit 1's migration had never been run there — so the copy was migrated before
any of this was measured.

## The writers — re-derived, not inherited

| Table | Writers |
|---|---|
| `finance_payments` | `RecordPayment:94`, `RecordAccountPayment:91`, `PostOpeningBalanceBatch:241` (`origin = 'migrated'` → NULL) |
| `finance_fee_items` | `CreateFeeSchedule:56` |
| `finance_invoice_lines` | `GenerateInvoice:212` — out of scope |

**`GradingSchemeController:75` also matches `items()->create(` and is NOT a fee-item writer** —
`GradingScheme::items()` returns `GradingSchemeItem`, a different table. Stated because a grep for
that call finds both, and a near-match is how #229's regex missed three sites.

**One writer the survey missed, found by the drive fixture failing:** `DriveFinanceStates` calls
`RecordPayment::handle` from production console code. It is the same class of site Larastan caught in
#229 — not reachable from the test suite, because nothing drives it. It now creates its own fixture
account.

Diff reconciled against the survey: **42 test call sites transformed, 42 expected.**

## The design — a CHECK, not a NOT NULL

`bank_account_id` on `finance_payments` is **nullable at the column**, and the rule is a CHECK keyed
on `origin`:

```
((origin COLLATE utf8mb4_bin = 'portal'   AND bank_account_id IS NOT NULL)
 OR
 (origin COLLATE utf8mb4_bin = 'migrated' AND bank_account_id IS NULL))
```

That is not a weaker NOT NULL, it is a different and stronger statement. A portal payment MUST name
the account it landed in; a migrated one MUST NOT, because money WCBS collected before cutover never
entered one of our accounts and pointing it at one asserts a fact that is false. **A plain NOT NULL
could only express the first half**, and would force every imported row to name an account it never
touched.

The precedent is on the same table in the opposite direction — `external_reference` is populated for
migrated rows and null for portal ones — and two things were carried from that migration's docblock:
`COLLATE utf8mb4_bin` (without it `= 'portal'` also matches `'PORTAL'`), and that
`finance_payments_no_update` fires **ahead of** CHECK evaluation, so on this table the CHECK's live
door is INSERT.

`finance_fee_items.bank_account_id` is **NOT NULL with no default** — the table is empty, which is
what makes that available.

## `finance_invoice_lines` — not in scope, and not added nullable either

S11's design is that the account travels from the fee item onto the line as a snapshot. It cannot
yet: `new-invoice-modal.tsx` states in its own docblock that line entry is manual with no fee
catalog, so every line today is free text with no fee item behind it.

I also did **not** add it nullable. A nullable column with no writer is a primitive ahead of its
consumer: null on every row until U1 ships, indistinguishable from "nobody recorded it", and an
invitation to read the absence as data. It arrives when fee items are actually the source of lines,
and it arrives NOT NULL then.

## The foreign keys are composite, because that is the uniform pattern

Ten existing finance FKs to a School-owned parent are `(child_id, school_id) -> parent(id,
school_id)`; **there is not one plain single-column FK among them.** The composite makes a
cross-School reference impossible at the database rather than merely improbable — the pair does not
exist in the parent.

That required a `UNIQUE (id, school_id)` on `finance_bank_accounts`, which commit 1 did not need
because nothing referenced it. Added here, named to match its nine siblings.

`ON DELETE RESTRICT` is belt to commit 1's braces: there is no destroy route and there must never be
one, and the restrict makes that true even for a hand-written DELETE.

## The `down()` was not reversible, and only the audit found it

The four-path rollback audit is the reason this is not shipping broken.

Creating a composite FK makes MySQL build a supporting index named after the constraint over
`(bank_account_id, school_id)`. `dropForeign` leaves it. Dropping the column then **shrinks** it to
`(school_id)` rather than removing it — and MySQL immediately adopts it as the supporting index for
`finance_fee_items_school_id_foreign`, after which it can never be dropped at all:

```
SQLSTATE[HY000]: General error: 1553
Cannot drop index 'finance_fee_items_bank_account_school_foreign': needed in a foreign key constraint
```

The name is then permanently taken, so the next `up()` **cannot create its foreign key and reports
success** — a column with no constraint behind it, which is precisely the state a rollback is
supposed to make impossible. Fixed by dropping the index **between** the FK and the column, while it
is still the full composite and unclaimed.

Audit after the fix — two complete cycles:

```
  1 applied          payCol=1 itemCol=1 CHECK=1 FKpay=1 FKitem=1 parentUq=1 | leftover index pay=1 item=1
  2 after down()     payCol=0 itemCol=0 CHECK=0 FKpay=0 FKitem=0 parentUq=0 | leftover index pay=0 item=0
  3 after up()       payCol=1 itemCol=1 CHECK=1 FKpay=1 FKitem=1 parentUq=1 | leftover index pay=1 item=1
  4 down() again     payCol=0 itemCol=0 CHECK=0 FKpay=0 FKitem=0 parentUq=0 | leftover index pay=0 item=0
  5 up() again       payCol=1 itemCol=1 CHECK=1 FKpay=1 FKitem=1 parentUq=1 | leftover index pay=1 item=1
```

### Two process mistakes on the way there

- **`migrate:rollback --step=1` rolled back commit 1's trigger migration, not mine** — my own earlier
  cycling had shifted the batch numbers. That is the `--step=N` trap CLAUDE.md documents, walked into
  directly. The audit now calls `up()`/`down()` on the migration object, which has no batch
  semantics at all.
- **I diagnosed the index problem from a database already corrupted by those mis-targeted
  rollbacks**, and reported a mechanism confidently that turned out to be wrong. The real one only
  appeared once I stopped suppressing stderr with `>/dev/null` — I had hidden the 1553 from myself.

## The modal — break proved first

```
[before] a bank-account field is present: false
[before] 422 …/payments {"errors":{"bank_account_id":["The bank account id field is required."]}}
```

After:

```
[after] a bank-account field is present: true
[after] options: ["Select an account…","Drive account — Drive Bank 9000000001"]
        pre-selected: "a276f582-74d3-4a87-a779-7b7e4075ad7d"
[after] 201 …/payments  reference=4
```

Read back out of the drive database:

```
payment#1..4  origin=portal  received_at=2026-08-10  bank_account_id=1 (Drive account)
```

**Active accounts only**, and the API deliberately returns deactivated ones too so a historical
payment can still render the name of an account that has since been retired. **Pre-selected only when
there is exactly one** — a pre-fill the operator sees and can change, not a default, for the same
reason the received date is. With two or more there is a real choice and guessing would assert a
destination nobody picked, on a row that is append-only.

The empty case says so rather than showing an empty dropdown: *"This school has no active bank
account, so a payment cannot be recorded. Add one under Finance → Bank accounts first."*

## The PRE-deploy line — the data half the commit split does not solve

`docs/handoff/post-deploy-tasks.md` gains a step requiring every school to have at least one ACTIVE
bank account before this migration's constraint takes effect, with the per-school query beside it.

**This is the third instance of one shape this week**, and the pattern is always *the requirement
ships before the thing that satisfies it*:

| # | Branch | The requirement | What was missing |
|---|---|---|---|
| 1 | #223 | the import screen accepted only CSV | the template it issued was `.xlsx` |
| 2 | #229 | `received_at` required at the FormRequest | no field on the modal to supply it |
| 3 | here | `bank_account_id` required for portal payments | no guaranteed account to point at |

The two-commit split handles the CODE half — commit 1 shipped the table and the screen so there is a
way to create an account before one is required. **It does not handle the DATA half**: nothing makes
an account EXIST. Run against the copy just now, the query answers the question immediately:

```
  school#1    active_bank_accounts=0   <-- WOULD BLOCK PAYMENTS
  school#2    active_bank_accounts=0   <-- WOULD BLOCK PAYMENTS
```

Both schools would be unable to record a payment on deploy day.

## Noted, not built — the mismatch exception now has data to fire on

Money received into account A settling a line destined for account B is **detectable for the first
time**. Phase 6 already specifies a bank-account mismatch exception; it has never had the data to
fire on. As of this commit the trigger exists in principle and **the detector does not** — the fee
item carries a destination and the payment carries a landing account, and nothing compares them.

## Proof

```
DB_DATABASE=portal_testing ./vendor/bin/pest tests/Feature/Finance
{"tool":"pest","result":"passed","tests":442,"passed":442,"assertions":1957}

tests/Feature/Finance/BankAccountForeignKeysTest.php — 6 arms, all green
```

### bin/quality — raw, unedited (ANSI stripped)

```
quality gate — base 2d5428e

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
       Pint (check) on 37 changed PHP file(s)
       Prettier (check) on 1 changed file(s)
       ESLint on 1 changed file(s)
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

## The watched reds — observed in the running database

**RED 1 — the CHECK's portal half removed.**

```
RUNNING DATABASE CHECK: ((((`origin` collate utf8mb4_bin) = 'portal') and (1 = 1)) or …
FAILED: A portal payment naming no bank account was accepted.
```

**RED 2 — a single-column foreign key instead of the composite**, which is the shape this repository
does not use anywhere:

```
RUNNING DATABASE FK columns: bank_account_id
FAILED: A payment in one School was allowed to name another School's bank account.
```

## The suite exposed a live bug in itself, because the date rolled over mid-session

Seven tests failed with:

```
"received_at_reason": ["The received at reason field is required unless received at is in 2026-08-10."]
```

It was past midnight in Lagos and still the 9th in UTC. The test payloads send `now()->toDateString()`
— the SERVER's day — while the FormRequest requires `SchoolDay::today()`, the SCHOOL's. **Those tests
only passed 23 hours out of 24**, and had the branch been finished an hour earlier nobody would have
noticed. Seventeen files now use `SchoolDay::today()` where the value means an operator's business
date.

One assertion went the other way and is worth keeping straight: `posted_at` must still be compared
against `now()`, because `SubledgerPoster` stamps it with the server clock **by design** — it records
when the row was written, not which period it belongs to. Changing that one to `SchoolDay::today()`
made it fail, which is the two columns demonstrating they are genuinely different questions.

## Not done

- **No invoice-line column**, argued above rather than omitted.
- **No mismatch detector** — noted above; specified in Phase 6, not built here.
- **No fee-schedule UI**, so `finance_fee_items.bank_account_id` forces an API-contract change only.
  There is no screen to break, which was checked rather than assumed: the finance pages are
  approvals, bank-accounts, index, opening-balances and statement.
