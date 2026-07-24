# Finance — next increment: sequencing the five PENDING policy items

**Status: PLAN FOR REVIEW. Nothing built. 2026-07-21.**

Re-derived against the code, not read off the policy's marks — and two of those marks
turned out stale in the "already done" direction.

---

## Per-item true state

### 1. Banker's rounding op — **GATED, and it must be last**

`App\Support\Money` exposes `plus`, `minus`, `times(int)`, `equals`, `isZero`,
`isNegative`. **There is no division or split operation**, which is correct: the policy
ties rounding to installments and percentage splits.

**Its consumer does not exist.** Searched `app/Finance/` for installment, schedule,
discount, waiver, percentage — **zero** occurrences of any of them. There is nothing in
the module that divides an amount.

Building the split/allocate op now would be exactly the "front-load a primitive ahead of
its consumer" mistake (§28.4): a half-to-even + remainder-on-final implementation with
no caller is a guess at an interface, verified only against tests we also invented.
**Sequence it after item 3**, which is what will first need to divide.

Also: `Money`'s docblock still says _"accounting-policy.md is unsigned, so that policy
does not exist yet"_ (line 27) and refers to _"the unsigned §12.3 rounding policy"_
(line 112). Both are now stale — the policy **is** signed. Per the standing note, that
docblock correction **lands with the rounding op**, not before, so the code never claims
a capability it lacks.

### 2. VOID status + exclude-void scope — **ALREADY BUILT. Nothing to do.**

The policy marks this **PENDING (slice 2)**. Slice 2 shipped it:

- `InvoiceStatus` has `case Void = 'void'` alongside `Issued`.
- `Invoice::isVoid()` exists.
- `Invoice::scopeExcludingVoid()` exists — and is deliberately a **named** scope, not a
  global one, so `{invoice:uuid}` route binding still resolves a voided invoice and the
  double-void guard keeps working.

**Action: correct the policy's mark, not the code.** No slice needed.

### 3. Waiver / discount presentation — **DOMAIN, not presentation. The biggest item.**

The framing question was "presentation over existing snapshot lines, or a new domain
concept?" It is unambiguously **domain**:

- `InvoiceLineSpec` carries `description`, `amount`, `feeItemId` — **no line type**.
- `GenerateInvoice:106` throws `BusinessRuleException('Every invoice line amount must be
positive.')` on any zero-or-negative line.
- `GenerateInvoiceRequest` independently enforces `lines.*.amount_minor >= 1`.

So a waiver **cannot be expressed at all today** — not as a negative line, not as a
typed line. Shipping it means either a line-type concept or relaxing the positivity
invariant, and it interacts with:

- **F6** (`total = SUM(lines)`) and its BEFORE-UPDATE immutability trigger;
- the deployed `finance_invoice_lines` schema (a new column);
- the wire contract for lines.

**Blast radius: highest of the five.** One-way-ish: once waiver lines exist in prod data,
the shape is hard to change.

**It is also the first _dividing_ consumer** — percentage waivers are what make
half-to-even rounding real. **Item 3 gates item 1.**

### 4. Per-School invoice-number prefix — **GATED on item 5, plus an unflagged schema fork**

The sequence itself is already per-School: `Sequences::next('finance_invoice',
(string) $enrollment->schoolId)`. Gap-tolerant uniqueness is ENFORCED and correct as-is.
Only the **prefix/format** is missing.

**The fork the policy does not mention:** `finance_invoices.number` is
`unsignedBigInteger` with `UNIQUE(school_id, number)`. **A prefix cannot be stored in
that column.** Two options, very different in cost:

| option                                                                                | cost                                                                                                 | notes                                            |
| ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------------------ |
| **(a) Presentation-derived** — keep the integer, render `prefix + number` at the edge | **No migration.** No change to deployed Finance tables                                               | The unique index and sequence stay exactly as-is |
| **(b) Stored** — widen to string or add `display_number`                              | **Migration against a deployed table with real invoices**, plus backfill and a unique-index decision | Higher stakes now that prod data exists          |

**(a) is strongly preferred** unless someone needs to _search_ on the prefixed form — a
question worth confirming before the slice opens.

### 5. School-scoped Finance config — **no unbuilt prerequisite, but no consumer alone**

Nothing exists: no `config/finance.php`, no School-settings table, no service. This is
the infrastructure several items sit on (prefix now, approver/repeat settings later).

**But building it alone repeats the very trap that disqualifies item 1.** A config table
with no reader is a guess at a schema. It needs to ship **with its first consumer**.

---

## Dependency graph

```
2 VOID ............................ DONE (policy mark stale)

5 School-scoped config ──gates──► 4 Per-School prefix
        └── needs a consumer to be shaped honestly ──┘   (pair them)

3 Waiver/discount (domain) ──gates──► 1 Banker's rounding
        (first dividing consumer)        (no consumer today)
```

Two independent chains. Neither touches the other, so the order between them is a
choice about risk appetite, not dependency.

---

## Recommended first slice: **5 + 4 together — per-School invoice-number prefix, and the config it needs**

Not "item 5 first". Config and its first consumer ship **together**, for the same reason
rounding waits for item 3: infrastructure without a reader is unverifiable.

**Why this is first**

- **No unbuilt prerequisite.** Everything it needs exists.
- **Bounded**, and with option (a) it needs **no migration against deployed Finance
  tables** — the single most valuable property now that prod data exists.
- **Useful on its own** — a real, visible change (invoice numbers become
  `BRK-1042` rather than `1042`), unlike config-only.
- **Shapes the config table against one real reader**, so the schema is answering a
  question rather than anticipating one.
- **Low one-way-ness.** A presentation-derived prefix is reversible; nothing is written
  that cannot be re-rendered differently tomorrow.

**Rough shape**

1. A School-scoped Finance settings store (one row per School; `invoice_number_prefix`
   first, deliberately extensible for approver/repeat later). **New table only** — no
   change to `finance_invoices`.
2. A small read-side accessor, School-scoped through `ActiveSchool`, with a documented
   default when unset.
3. Prefix applied at the **presentation edge** (resource/serializer), so `number`
   remains the integer the unique index and sequence depend on.
4. Tests: default when unconfigured; per-School override; two Schools do not see each
   other's prefix; the underlying sequence and `UNIQUE(school_id, number)` unchanged.

**What it explicitly does NOT pull in**

- No `Money` op, no rounding, no division — item 1 stays gated.
- No waiver/discount, no line types, no touching the positivity invariant or F6.
- No approver/maker-checker or repeat settings — the table is _extensible_ for them, not
  populated with them.
- No change to `finance_invoices`, its unique index, or the Money wire contract.

**Then, in order**

- **3 — waiver/discount (domain).** The large one. Needs its own design pass: line type
  vs. relaxed positivity, the F6 interaction, and a migration on deployed line data.
- **1 — banker's rounding.** Opens _after_ 3, once percentage waivers give the split op a
  real caller. The `Money` docblock correction lands here.

---

## What the deployed state changes

Finance is live, so "real prod data exists" is now a first-class constraint:

- **Item 3 needs a migration against deployed `finance_invoice_lines`** and touches the
  F6 total invariant plus its immutability trigger. Highest stakes of the five.
- **Item 4 needs a migration only under option (b)**; option (a) avoids it entirely —
  which is most of why it is recommended.
- **Items 1 and 5 need no Finance-table migration** (5 adds a new table; 1 is pure VO
  code).
- Anything touching the `{amount_minor, currency}` wire shape is now a
  backward-compatibility question, not a free change.

Per the standing deploy runbook, any of these still promotes **one at a time on the
merged HEAD**, and a surprise tsc regression is a **corrupt-`node_modules` suspect
first** (`rm -rf node_modules`, not `--frozen-lockfile`).

---

## This is a plan for review

Before the first slice opens, four things are worth confirming — three are genuine
decisions, one is a correction:

1. **Prefix: presentation-derived (a) or stored (b)?** The recommendation is (a). It
   flips to (b) only if invoice numbers must be **searchable/reportable in prefixed
   form**, which is a product question.
2. **Is pairing 5+4 agreed?** The alternative — config alone — is explicitly not
   recommended, and the reasoning is the same one that defers item 1.
3. **Which chain first?** The 5+4 chain is recommended as the re-opening slice because
   it is bounded and migration-free. Going straight to item 3 is defensible if waivers
   are commercially urgent, but it is the largest and least reversible of the five.
4. **The policy doc needs two stale marks corrected** (not a decision, a fix): VOID is
   built, and the "422-vs-400 convention still pending" note at §9 was resolved — the
   app standardised on 422 app-wide. Worth folding into whichever slice opens next.
