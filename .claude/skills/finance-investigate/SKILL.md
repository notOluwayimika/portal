---
name: finance-investigate
description: How to derive a finding on the Brookstone platform that survives contact with the repo — classifying evidence, citing file:line, deriving database facts without a client, bite-proving before you believe a guard, and reporting under the ids-not-names privacy rule. Load this whenever you are asked to investigate, diagnose, audit, check whether something is enforced, work out why a readiness row or a gate behaves as it does, or confirm a claim someone else made about the codebase. Use it before you assert anything about app/Finance, RBAC, migrations, gates or the database.
---

# Investigating

An investigation produces one thing: a finding you can stand behind when
somebody opens the file and checks. Everything below is in service of that.

## Classify your evidence before you write

Every sentence in a finding rests on one of four things, and they are not
interchangeable:

- **Read** — you opened the file and the line says this. Cite `path:LINE`.
- **Ran** — you executed it and this is the output. Paste the output.
- **Told** — a report, PR body, doc or previous session says this. It is a
  claim until you promote it to Read or Ran.
- **Inferred** — you reasoned to it. Say so, and say from what.

Most bad findings are an Inferred sentence wearing a Read sentence's clothes.
When you catch yourself unable to say which of the four a claim is, that is the
claim to go check.

Promote before you publish: anything load-bearing must be Read or Ran. Told and
Inferred are allowed in a finding, but they must be labelled as such in the text.

## Read the repo, not the description of the repo

Open the actual file. Specifically:

- The migration that **ran**, not the one that was described.
- The **rename** migration, not the create filenames.
- The current line numbers, re-derived — they move.
- The report's claims checked against the code, never the report against itself.

If a doc and the code disagree, the code is what is deployed and the doc is a
finding.

## Bite-prove before you believe a guard

Finding that a guard exists is not finding that it works. Before you write "this
is enforced", make it fail: plant the exact violation it claims to catch, watch
it catch it, restore. Paste the red and the green.

The failure modes this catches are not exotic — a test whose assertion is
vacuous, a gate that never runs on the relevant path, a lint that silently skips,
a pre-flight ordered after the write it was meant to prevent. Each of those has
happened on this project and each of them was green.

Inverse case, equally important: if you claim something is **not** enforced,
find the enforcement you would expect and show it absent. "Enforced by nothing"
is an easy sentence to write and it has been wrong here before — the invariant
was gated in a test file nobody had grepped.

## Deriving database facts

Findings come from the local copy of production, not from production. But the
advising side cannot execute SQL — no client, no PHP, no network, and MySQL is
on the project lead's own loopback.

So a database finding is written as **the query the implementing agent runs**,
folded into the brief, with the expected shape of the answer stated. Never as a
separate ask to the project lead, and never as an instruction to go look at
production.

Query hygiene that has cost time here: `COLLATE utf8mb4_bin` rather than
`REGEXP BINARY`; `COALESCE` around `SUM(bool)` because an empty table yields
NULL; the `model_type` filter with doubled backslashes on `model_has_roles`;
`whereNull('school_id')` when you mean global roles rather than C6 school-scoped
ones; and joining `TABLE_CONSTRAINTS` to reach a CHECK's table name.

## Privacy — ids, counts, structure

Report `user#<id>` and `school#<id>`. Never a name, never an email, never an
amount, never row contents. Structure and totals answer every question worth
asking: how many holders, how many schools affected, which pair, which role.

If a finding seems to need a name, it does not — reframe it around the id. This
rule has been broken here once and it is not a formality.

## Re-derive, never carry

Counts, holder totals, line numbers, step counts, commit shas, table names:
derive them at the moment you use them. A number carried from an earlier session
is the single most reliable source of confident errors on this project.

## Shape of a finding

State it in this order, and keep it short:

1. **What is true** — one sentence, the fact itself.
2. **Evidence** — `path:LINE`, or the pasted output.
3. **Why it matters** — the concrete failure it causes or allows, not an
   abstraction. Name the environment it bites in (fresh install? existing
   install? production only?) — the two most expensive defects here were
   invisible locally and live on production.
4. **Severity** — stop / fix / ticket, with one line on why that level.
5. **What would close it** — the mechanism, not the intention.

If you found something that contradicts the premise you were given, that goes
first, before the work you were asked to do.

## When the investigation contradicts the brief

Stop and say so before proceeding. A brief executed faithfully on a false
premise produces a confidently wrong change, and it is expensive to unwind
because it looks finished.

Equally: if you get to the end and the honest answer is "I could not determine
this", say that. An inconclusive investigation reported as inconclusive is
useful. An inconclusive investigation reported as a conclusion is a defect with
a delay fuse.

See `references/evidence-checklist.md` for the pre-publication pass.
