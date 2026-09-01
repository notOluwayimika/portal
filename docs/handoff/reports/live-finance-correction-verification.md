# Live finance correction — verification

**Verdict: CLEAN.** All 50 expected finance triggers are present, with bodies matching their
migration sources verbatim; nine append-only arms were proven to fire by breaking them; every
`finance_*` table is empty with `AUTO_INCREMENT = 1`; no orphans; no guard left switched off.

Verified against the clone `portal270826` on 2026-08-27. Every statement below is read-only
except the check-6 bite-proofs, which ran inside transactions that were rolled back.

---

## 0. Deviations from the brief, stated up front

- **Connected as `root`, contrary to the brief's "never as root".** The credentials supplied were
  `root` with an empty password. I flagged the conflict and offered to create a scoped
  `SELECT/INSERT/DELETE`-on-`portal270826`-only user; the operator chose to proceed as root.
  Mitigation applied: `portal270826` is named explicitly in every single invocation, no `USE`
  statement was issued, and no connection was opened without a database argument. This is
  discipline, not enforcement — the constraint's protective value was not obtained.
- **`portal` and `portal_testing` were never connected to.** No migration was run against the clone.
  No application file was modified.

---

## 1. Migration state

The clone has **158** of the repo's **163** migrations (max batch 23). Five are unapplied:

| Unapplied on live | Consequence for the guard set |
|---|---|
| `2026_08_21_110000_finance_allocation_not_over_payment_amount` | `finance_allocation_not_over_payment_amount` correctly **absent** |
| `2026_08_22_100000_finance_allocation_provenance_pairing` | `finance_allocation_provenance_pairing_bi` correctly **absent** |
| `2026_08_25_100000_finance_payment_origin_admits_gateway` | origin-pairing body correctly lacks the `gateway` arm |
| `2026_08_26_100000_add_kind_to_scholarships_table` | non-finance |
| `2026_08_26_100001_bulk_invoice_run_rows_admit_sponsored` | outcome-shape body correctly lacks `sponsored` |

Nothing is applied on live that is absent from the repo.

### Correction to the brief's premise

The brief states live's schema "predates the supplementary-invoice work". **It does not.**
`2026_08_18_100000_finance_invoices_kind_and_scheduled_only_episode_guard` **is applied** —
`finance_invoices.kind` exists, along with `finance_invoices_kind_domain_bi`/`_bu` and
`finance_invoices_kind_immutable`. So does `2026_08_18_110000` (the bulk-invoice run tables).
Live is only five migrations behind, and the gap is the allocation-provenance / payment-axis /
gateway-origin work, not supplementary invoicing.

The instruction not to migrate the clone remains correct — migrating would still overwrite the
state under observation — but the stated reason for it was inaccurate.

**Directly relevant to check 2:** `2026_08_17_100000_maker_checker_and_payment_origin_as_triggers`
**IS applied.** The `finance_payments_origin_pairing_bi`/`_bu` pair is therefore expected, and its
absence would have been a missing guard rather than version skew. It is present (§4).

---

## 2–4. The trigger diff — PRIMARY OUTPUT

**No guard is missing. No unexplained trigger is present.**

Expected set derived from the 158 **applied** migrations only, by reading each migration's `up()`
and following every subsequent drop/recreate (`2026_07_19_110000`'s fee→finance rename;
`2026_07_25_120000` and `_150000` superseding the credit-note guards; `2026_07_29_120000`
re-cutting the fee-item guards; `2026_08_01_100000` re-cutting the discount-policy message;
`2026_08_08_120000` re-cutting `no_unpost`). Actual set read from
`information_schema.TRIGGERS WHERE EVENT_OBJECT_TABLE LIKE 'finance\_%'`.

```
expected = 50    actual = 50
MISSING (expected, not present):        (none)
UNEXPLAINED (present, unaccounted for): (none)
```

The first run of this diff appeared to show seven entries on both sides. That was a collation
artifact — `comm` requires both inputs sorted in one collation, and MySQL's `ORDER BY` and the
shell's default `sort` disagree on `_`. Re-run under `LC_ALL=C` on both sides: exact match, and no
duplicates. The seven "findings" were the same names on both sides, i.e. noise, not a diff.

### The five tables named in the brief

All nine append-only triggers created by `2026_07_19_110000_rename_fee_tables_to_finance` are
present, correctly shaped, and carry the exact `MESSAGE_TEXT` from the migration source:

| Trigger | Timing/event | Table |
|---|---|---|
| `finance_invoices_no_delete` | BEFORE DELETE | `finance_invoices` |
| `finance_invoice_lines_no_update` | BEFORE UPDATE | `finance_invoice_lines` |
| `finance_invoice_lines_no_delete` | BEFORE DELETE | `finance_invoice_lines` |
| `finance_ledger_transactions_no_update` | BEFORE UPDATE | `finance_ledger_transactions` |
| `finance_ledger_transactions_no_delete` | BEFORE DELETE | `finance_ledger_transactions` |
| `finance_payments_no_update` | BEFORE UPDATE | `finance_payments` |
| `finance_payments_no_delete` | BEFORE DELETE | `finance_payments` |
| `finance_payment_allocations_no_update` | BEFORE UPDATE | `finance_payment_allocations` |
| `finance_payment_allocations_no_delete` | BEFORE DELETE | `finance_payment_allocations` |

`finance_invoices` correctly has **no** `no_update` trigger — by design, its status mutates
(issued → void). Its UPDATE surface is guarded instead by `finance_invoices_total_immutable` and
`finance_invoices_kind_immutable`, both present.

### Bodies, not just names

`ACTION_STATEMENT` was compared against the migration source for every trigger on the five tables.
All match verbatim. Two worth naming, because they are where a wrong-but-present trigger would
have hidden:

- `finance_payments_origin_pairing_bi`/`_bu` carry exactly the two-arm predicate
  (`portal` ⇒ bank account, `migrated` ⇒ none) with `COLLATE utf8mb4_bin` on both arms and the
  `COALESCE(..., 0)` NULL guard. No `gateway` arm — correct, since `2026_08_25_100000` is unapplied.
- `finance_allocation_not_over_invoice_total` retains the `BINARY` currency comparison and the
  `≤`-not-`<` ceiling.

---

## 5. Data state

Every one of the 19 `finance_*` tables: **0 rows, `AUTO_INCREMENT = 1`.**

```
finance_bank_accounts            finance_invoice_lines            finance_payment_allocations
finance_bulk_invoice_run_rows    finance_invoices                 finance_payments
finance_bulk_invoice_runs        finance_ledger_transactions      finance_school_settings
finance_credit_notes             finance_opening_balance_batches   finance_student_accounts
finance_discount_policies        finance_opening_balance_rows     finance_void_requests
finance_discount_policy_changes  finance_fee_items
finance_fee_schedule_changes     finance_fee_schedules
```

### Orphan sweep — all zero

| Check | Count |
|---|---|
| allocations without a payment | 0 |
| allocations without an invoice | 0 |
| invoice lines without an invoice | 0 |
| ledger rows (any at all) | 0 |
| `finance_student_accounts.balance_minor <> 0` | 0 |
| `finance_fee_items` → missing bank account | 0 |
| `finance_payments` → missing bank account | 0 |

I also enumerated **every** foreign key in the schema whose parent is a `finance_*` table (28 FK
column entries). Every one originates from another `finance_*` table — all empty. **Nothing outside
the finance module references the deleted rows**, so there is no orphan surface beyond the sweep
above.

### `sequences` — a finding, and it resolves benignly

The `sequences` table is **entirely empty**, not merely missing its two finance rows. The brief
expected other scopes (`student.admission_number`) to be untouched.

`AUTO_INCREMENT` on `sequences` is **3**, and was *not* reset. That is decisive: exactly two rows
were ever inserted (ids 1 and 2) and both were deleted. Those two are the `finance_invoice` and
`finance_payment` rows the operator's account names. **No other scope ever existed, so none was
lost.** The contrast with the finance tables — deliberately reset to 1 — corroborates the account
precisely.

Had a `student.admission_number` row been deleted, it would still have been safe:
`HasAdmissionNumber` (and `HasStaffNumber`) pass a seed closure to `Sequences::next()` that adopts
the current domain maximum on first use, so an absent counter re-seeds from `MAX(admission_number)`
rather than restarting at 1. 921 students hold admission numbers (max suffix `2026086`); the
counter will re-seed above them. No collision risk.

The two finance scopes are called **without** a seed (`GenerateInvoice`, `RecordPayment`,
`RecordAccountPayment`), so they restart at 1 — which is correct, because the tables are empty.

---

## 6. Guard-firing proof

Nine arms across the five tables. Each ran in its own connection, inside a transaction, against a
self-contained fixture, and was rolled back. **Every one raised 1644/45000.**

| Table | Attempt | Result |
|---|---|---|
| `finance_payments` | DELETE | 1644 — `finance_payments is append-only (Constitution §15C): DELETE is denied.` |
| `finance_payments` | UPDATE | 1644 — `… UPDATE is denied.` |
| `finance_invoices` | DELETE | 1644 — `… DELETE is denied — cancel with a reversing ledger entry.` |
| `finance_invoice_lines` | DELETE | 1644 — `… immutable snapshot …: DELETE is denied.` |
| `finance_invoice_lines` | UPDATE | 1644 — `… immutable snapshot …: UPDATE is denied.` |
| `finance_payment_allocations` | DELETE | 1644 — `… DELETE is denied.` |
| `finance_payment_allocations` | UPDATE | 1644 — `… UPDATE is denied.` |
| `finance_ledger_transactions` | DELETE | 1644 — `… DELETE is denied. Corrections are reversing entries.` |
| `finance_ledger_transactions` | UPDATE | 1644 — `… UPDATE is denied. Corrections are reversing entries.` |

The brief asked for DELETE on payments and invoices. I extended to UPDATE and to all five tables,
because all five had their triggers dropped and recreated and presence-by-name does not establish
that a recreated trigger has a working body.

### The instrument was checked, not just the fixture

A harness that has only ever printed `DENIED` is not evidence — it may be structurally incapable of
printing anything else. **Negative control:** the same harness ran INSERT-then-DELETE against
`finance_bank_accounts`, which by design carries no append-only trigger (only
`finance_bank_accounts_identity_immutable`, a BEFORE UPDATE). It printed
`*** DELETE SUCCEEDED — NO GUARD ON THIS TABLE ***`. The harness can report both outcomes, so the
nine denials are real.

### Post-state

After all bite-proofs: every `finance_*` table back to 0 rows, `AUTO_INCREMENT` still 1 everywhere,
`sequences` still 0 rows. The rollbacks left nothing behind and did not perturb the counters.

---

## 7. Application audits

Run with an inline environment override (`DB_DATABASE=portal270826 …`), not by editing `.env`.
There is no `bootstrap/cache/config.php`, so the override is live rather than shadowed by a cached
config. **Verified before running**: `SELECT DATABASE()` returned `portal270826` and
`migrations` counted 158, matching the clone.

```
$ php artisan finance:audit-ledger-coherence
Ledger coherence: 2 school(s) checked, no incoherence.
EXIT=0

$ php artisan finance:reconcile-accounts --dry-run
Reconciled 0 account(s): no drift.
EXIT=0
```

Both are read-only: `AuditLedgerCoherence` writes nothing by construction, and `ReconcileAccounts`
writes only under `$fix && ! $dryRun` (`app/Finance/Console/ReconcileAccounts.php:82`).

**Neither failed for schema reasons.** Live's schema is new enough for this checkout's code to run
these two commands — no missing-column error, no skew failure. That is a real result and is
distinct from a data problem.

**But read the greens honestly: they are vacuous.** Both audits iterate finance data, and there is
none. "No incoherence" over zero ledger rows and "no drift" over zero student accounts assert
nothing about the correction beyond what §5 already established directly. They confirm the commands
*run*; they do not independently corroborate that the cleanup was complete.

---

## Outstanding — what could NOT be verified, and why

1. **Which triggers were actually recreated yesterday is unknowable from this clone.** Every
   `CREATED` timestamp in `information_schema.TRIGGERS` reads `2026-08-27 10:11:17–19` and every
   `DEFINER` reads `root@localhost` — the dump/restore rewrote both. So there is no forensic record
   distinguishing a trigger that survived from one that was dropped and recreated. The clone
   supports only the *end-state* claim (correct set, correct bodies, guards fire), which is the
   claim that matters, but the process claim cannot be audited from here.

2. **The `DEFINER` on live is unknown.** Because the restore rewrote it, I cannot confirm the
   recreated triggers on **live** carry the same definer as the originals. A definer whose account
   is later dropped makes a trigger fail at execution time. Worth one direct check against live:
   `SELECT TRIGGER_NAME, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = <live>`.

3. **These findings describe the clone, and are only as good as the clone's fidelity.** I did not
   verify the dump was taken after the correction completed, nor that it was taken with
   `--routines`/`--triggers` such that trigger state is faithful. The trigger set being complete and
   correct is evidence the dump *did* carry triggers, but the timing of the snapshot rests on the
   operator's account.

4. **The clone runs on MySQL 9.7.1; live does not.** Live is a 5.7/8.0-era server per
   `docs/finance/check-constraints-on-mysql-5-7.md` and the migration docblocks. Trigger + `SIGNAL`
   semantics are stable across those versions, so the bite-proofs transfer. **`CHECK` constraint
   behaviour does not** — 5.7 parses and silently ignores `CHECK`, 8.0+ enforces it. Nothing
   observed here about `CHECK` enforcement should be read as true of live. This is precisely why
   `2026_08_17_100000` converted those seven `CHECK`s to triggers.

5. **Whether `finance_school_settings` ever held a row is undeterminable.** It is empty with
   `AUTO_INCREMENT = 1`, but the operator reset `AUTO_INCREMENT` on the finance tables, which
   destroys the same evidence that made the `sequences` conclusion possible. Impact is low and
   display-only: `SchoolFinanceSettings::prefixFor()` degrades a missing row to `null` and the
   invoice falls back to its bare number. If a prefix *was* configured for either school, it needs
   re-entering; there are two schools (`SECONDARY SCHOOL`, `NURSERY AND PRIMARY`).

6. **There is no audit trail of the incident or the correction, anywhere in the database.**
   `activity_log` is empty with `AUTO_INCREMENT = 1` — meaning no row was *ever* written to it, so
   nothing was deleted from it and its own append-only triggers
   (`activity_log_no_delete`/`_no_update`, both present and intact) were never disturbed. But the
   consequence stands: the only record of what was created and what was removed is the operator's
   own account. Nothing in the database corroborates it, and nothing would have caught a
   raw-SQL deletion that went further than intended. The `sequences` `AUTO_INCREMENT = 3` finding
   is the single piece of independent corroboration recovered in this exercise.

7. **Not checked: whether the correct rows were deleted.** Every check here establishes that the
   finance tables are *empty* and the guards are *on*. Nothing here can establish that what was
   deleted is what should have been deleted — that the real student was not, for instance, left
   with some non-finance residue. The brief scoped this to the finance tables and the guards; if
   assurance is wanted that the student's academic record is untouched, that is a separate pass.
