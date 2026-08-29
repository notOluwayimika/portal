# 24 string comparisons in finance triggers run under a case- and accent-insensitive collation

**Raised:** 2026-08-28, generalised from two defects measured on `feat/gateway-transaction-table`.
**Severity:** ticket for the pre-existing instances; two of them are worth looking at first (§3).
**Not fixed here.** The gateway tables are fixed on that branch and pinned by a tripwire; everything
below is other people's tables and belongs in its own change.

## The class

Every `finance_` table is `utf8mb4_unicode_ci`, which is case- **and accent**-insensitive. So inside a
trigger:

```sql
NEW.provider <=> OLD.provider          -- 'paystack' and 'PAYSTACK' compare EQUAL
NEW.amount_currency <=> OLD.amount_currency  -- 'NGN', 'ngn', 'ṄGN', 'NGŇ' all compare EQUAL
NEW.status = 'approved'                -- 'Approved' and 'APPROVED' both match
```

A **freeze** arm written that way does not freeze: the column can be rewritten to any collation-equal
variant. A **domain** arm written that way admits variants nobody wrote a filter for.

`2026_08_17_100000`'s docblock already records this class for the DOMAIN arms, and states the reason it
is hard to see: *omitting `COLLATE utf8mb4_bin` from ONE arm is the quiet failure, because the other
arms keep biting and the guard still looks alive.* What the gateway branch adds is that **it applies to
FREEZE and WRITE-ONCE arms too** — and those are the ones nobody thought of, because "immutable" reads
as a stronger word than it is.

## What was measured — and the count was WRONG TWICE before it was right

**The number in the first version of this ticket was 24. It is 29, across 10 triggers.** Both
corrections came from the scanner, not from the schema, and they are recorded because anyone re-running
this must not reproduce them:

1. **The first two scans did not match `<=>`.** They matched `=`, `<>` and `REGEXP`. `<=>` is the
   null-safe operator every *freeze* arm uses — so the instrument was blind to the majority case of
   the very defect it was written to find. Adding it moved 24 → 29.
2. **They also flagged `BINARY`-protected comparisons as bare.** This repo has **two** protection
   idioms, not one: `COLLATE utf8mb4_bin`, and the older `BINARY x` operator that
   `2026_07_26_140002` uses (later migrations moved to `COLLATE` because `NOT REGEXP BINARY` errors
   3995 on utf8mb4). A scan that only knows `COLLATE` reports a correctly-guarded comparison as a
   defect. Two comparisons were false positives.

The corrected sweep:

```
PROTECTED by COLLATE utf8mb4_bin : 7
PROTECTED by the BINARY operator : 2   <-- invisible to the first scan
GENUINELY BARE                   : 29  across 10 triggers
```

**Take the list below over the number**, and re-derive before acting: this is the fourth time on the
originating branch that a measuring instrument turned out to be blind to the axis it was measuring.

## What was measured

Scanned `information_schema.TRIGGERS.ACTION_STATEMENT` for every trigger on a `finance_` table,
restricted to comparisons whose column is `char`/`varchar`/`text`/`enum` — collation is meaningless on
an integer, and counting those inflates the number to 55 and buries the real ones.

```
finance triggers scanned                      : 58
STRING-column comparisons (corrected sweep)   : 38
  protected by COLLATE utf8mb4_bin            :  7
  protected by the BINARY operator            :  2
  GENUINELY BARE                              : 29  across 10 triggers
```

**Any re-run must match `<=>` AND know both protection idioms** — see the section above for what each
omission cost. Same family as the mutation summariser that counted only Pest's `failures` bucket: an
instrument blind to the axis it measures reports a clean sweep over the defect.

## §2b · THE DATED-BOUNDARY HYPOTHESIS IS FALSE — measured, and what replaces it is worse

A reasonable hypothesis on reading `2026_07_26_140002`: it records the superseded claim (§3.5 said
kind/status are compared against **literals** so the hazard "does not arise") and its correction under
#95, and writes `BINARY` on every comparison as a result. So perhaps the bare triggers are simply the
ones written **before** 2026-07-26, and the fix is a dated sweep rather than a triage.

**It does not hold.** Mapping each of the 10 bare-comparison triggers to the migration that last
installs it:

| Trigger | Installed by | Date |
|---|---|---|
| `finance_invoices_total_immutable` | `2026_07_19_120000_slice2_invoice_total_immutable…` | 07-19 |
| `finance_void_requests_update_guard` | `2026_07_25_140000_create_finance_void_requests` | 07-25 |
| `finance_credit_notes_insert_guard` | `2026_07_25_150000_finance_credit_note_requires_issued_invoice` | 07-25 |
| `finance_credit_notes_update_guard` | `2026_07_25_150000_…` | 07-25 |
| **`finance_discount_policy_changes_update_guard`** | **`2026_07_26_140001_create_finance_discount_policy_changes`** | **07-26** |
| `finance_fee_schedule_changes_update_guard` | `2026_07_28_120000_create_finance_fee_schedule_changes` | 07-28 |
| `finance_discount_policies_update_guard` | `2026_08_01_100000_fix_discount_policy_guard_message_quoting` | 08-01 |
| `finance_opening_balance_batches_no_delete_posted` | `2026_08_08_110000_opening_balance_posting_state_and_guards` | 08-08 |
| `finance_opening_balance_batches_no_unpost` | `2026_08_08_120000_opening_balance_posted_rows_are_terminal` | 08-08 |
| `finance_bank_accounts_identity_immutable` | `2026_08_10_110000_finance_bank_account_identity_is_immutable` | 08-10 |

**Six of the ten are AFTER the correction, and one of them is `140001` — the SIBLING of `140002`,
committed the same day.** The migration that wrote the discipline down and the migration next to it
that ignored it are adjacent files with adjacent timestamps.

**So there is no dated boundary to sweep, and the real finding is worse than a stale cohort:** the #95
correction was recorded in **one migration's docblock** and never became a rule. It did not propagate
to its own sibling on the same day, nor to any of the five later ones. That is the wallpaper principle
exactly — *a convention with no lint, gate or test behind it is a wish* — and it is why the fix for
this ticket is not only "add the collation" but **the tripwire that makes the next omission fail a
build.** One exists already for the two gateway tables; widening its table filter to `finance\_%` once
these 29 are fixed is a one-line change and is the point of having written it that way.

## §3 · The two worth looking at first

Most of the 24 are `uuid`, `reason`, `name` and identity columns where a case-variant rewrite is
undignified but not dangerous. **Two are domain comparisons on a `status` column**, which is the shape
that admits a value the rest of the system believes impossible:

| Trigger | Comparison |
|---|---|
| `finance_credit_notes_insert_guard` | `NEW.status = 'approved'` |
| `finance_credit_notes_update_guard` | `NEW.status = 'approved'`, `NEW.status <> 'approved'` |
| `finance_opening_balance_batches_no_delete_posted` | `NEW.status = 'posted'` |
| `finance_opening_balance_batches_no_unpost` | `NEW.status = 'posted'`, `NEW.status <> 'posted'` |

On credit notes the `'approved'` comparisons gate the **ceiling check** — the arm that stops a credit
note exceeding the invoice it credits. On opening balances they gate the terminal-state guard that
stops a posted batch being unposted, which the enum's own docblock calls terminal *at the database*.

**Whether either is reachable was NOT established here** and should not be assumed either way: it
depends on whether any writer can put a case variant into `status` in the first place, which is an
app-layer question these triggers do not answer. That is the first thing the fixing change should
measure — and note that a status column with no DB-level domain guard is itself the finding if so.

Two smaller ones in the same list, for completeness: `finance_bank_accounts_identity_immutable` compares
`bank_name` and `account_number` bare, and `account_number` is a reconciliation key; and
`finance_invoices_total_immutable` compares `total_currency` bare.

## The rule, and how to make it stick

**Every string comparison in a finance trigger that guards a value — domain, freeze, or write-once —
runs under `COLLATE utf8mb4_bin`.**

`tests/Feature/Finance/GatewayTransactionSchemaTest` now enforces this for the two gateway tables by
reading the installed trigger bodies back out of `information_schema` and failing on any bare
string-column comparison. **It is deliberately scoped to those two tables**, because widening it to all
`finance_` triggers today would fire on the 24 above and either block this branch or invite the one
thing this repo refuses: baselining a working tripwire as permanently-failing.

The right sequence is the ratchet's own: fix the 24, then widen the tripwire's table filter to
`finance\_%` and delete the scoping comment. Widening it is a one-line change and is the point of
writing it this way.

## Related

- `database/migrations/2026_08_17_100000_maker_checker_and_payment_origin_as_triggers.php` — where
  this class was first written down, for domain arms only. **On `staging`, readable now.**

**The following two are on `feat/gateway-transaction-table`, which is NOT merged and NOT pushed** at
the time this ticket was raised. They are named so the reference is honest rather than dangling — if
you want them before that branch lands, ask and they will be pushed:

- `database/migrations/2026_08_27_100000_create_finance_gateway_transactions.php` — the BINARY
  COLLATION rule in the class docblock, and the two guards that follow it.
- `docs/handoff/reports/feat-gateway-transaction-table.md` — the two measurements that generalised
  the class, and the tripwire that pins it for the two gateway tables.
