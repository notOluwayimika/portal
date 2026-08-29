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

## What was measured

Scanned `information_schema.TRIGGERS.ACTION_STATEMENT` for every trigger on a `finance_` table,
restricted to comparisons whose column is `char`/`varchar`/`text`/`enum` — collation is meaningless on
an integer, and counting those inflates the number to 55 and buries the real ones.

```
finance triggers scanned                      : 58
STRING-column comparisons                     : 48
  under COLLATE utf8mb4_bin                   : 24
  BARE (case- and accent-insensitive)         : 24
```

**The scan under-reports, and the reason matters.** Its first version matched `=`, `<>` and `REGEXP`
and **missed every `<=>`** — which is exactly the null-safe operator the freeze arms use, so it swept
cleanly over the defect it was written to find. Same family as the mutation summariser that counted
only Pest's `failures` bucket. Any re-run of this scan must match `<=>`.

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
