# The gateway insert guard is restated by hand on every change

**Raised:** 2026-09-01 · **From:** `feat/gateway-initiate` · **Severity:** ticket

## What

`finance_gateway_transactions_insert_guard` has now been written out **three times** — in
`2026_08_27_100000` (created), `2026_09_01_100000` (reference arm), and `2026_09_01_110000` (bill
arms). Each replacement hand-copies every arm that came before.

## Why it is not simply bad style

**MySQL 5.7 — which production runs — permits exactly ONE trigger per (table, event, timing).** A
trigger body cannot be patched, only replaced. So there is no mechanism-level fix available on the
production engine: adding an arm *requires* restating the others. MySQL 8.0 allows multiple triggers
per timing and would let each rule live in its own migration; the local engine already does.

## The hazard

A replacement that silently drops a sibling arm **installs cleanly**. Nothing fails. The guard
appears present, the table appears defended, and one rule is simply gone — the enumeration problem
this repository has now paid for at least three times (the CHECK-constraint test's named list, the
events update guard's frozen columns, and this).

## What holds it today

Each of the two newer migrations ends in an `assertShape()` that reads the INSTALLED body back from
`information_schema.TRIGGERS` and asserts **every arm by name**, not only the arm being added. That
one-sidedness is the specific trap: "is my new thing there?" is exactly the check that lets a
sibling vanish.

That is a real mechanism and it is why this is a ticket rather than a fix. But it is a mechanism
each author must remember to extend, which puts it in the class this project calls *a rule with no
local failure signal*.

## Options, none free

1. **Generate the body from one source.** A single class listing the arms, called by each migration
   — but migrations are dated acts and must not change behaviour when the shared source changes.
   A migration that reads today's list would rewrite history on a re-run.
2. **Split per-rule triggers when production reaches 8.0.** Correct, and blocked on a database
   version — but that version is about to be CHOSEN rather than merely waited for.

   **THE SHARED-TO-DEDICATED SERVER MOVE IS THE OPPORTUNITY, AND IT IS IMMINENT.** If the new server
   runs MySQL 8.0 or later, each rule can live in its own trigger and its own migration, and this
   whole class of hand-restatement disappears. If it lands on 5.7 again the constraint is inherited
   for the life of that box.

   So this is a reason to **establish the new server's MySQL version early**, during cutover
   planning, rather than discovering it afterwards. Raised here so it surfaces in that conversation
   instead of being rediscovered in six months by someone restating this trigger for the fourth
   time.
3. **A test — not a migration — that asserts the live trigger contains every arm the schema
   claims.** Independent of migration order, fails on any drop, and costs one file.

Option 3 is the cheapest real gate and does not wait on an engine upgrade. It is not built here
because the assertions currently live in the migrations and duplicating them without removing them
would be a third statement of the same list.
