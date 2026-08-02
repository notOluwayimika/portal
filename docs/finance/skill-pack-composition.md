# Brookstone Finance Skill Pack — how the five compose

Five skills. Read this once; it explains why the pack is shaped the way it is
and which skill loads when.

---

## The three constraints

Everything in the pack follows from these. They are not stylistic — each one
encodes something this project learned expensively, and violating any of them
produces a pack that looks more rigorous than what it replaced while protecting
less.

### Constraint 1 — Executor and Advisor are separate invocations, not modes in one conversation

This is the most important requirement in the pack.

The review discipline that made this project work was **structural**: one
context wrote the implementation prompt, a separate agent with its own context
implemented and reported, and the reviewer attacked the report without having
done the work. Because the reviewer didn't know what shortcuts were taken, it
had to ask. Because the implementer couldn't lean on the reviewer's reasoning,
it had to re-derive.

That separation caught a test whose setup silently no-op'd, a `tsc` baseline
calibrated against a corrupted tree, six enforcement gates that reported green
while blocking nothing, and a migration-rollback audit that passed while testing
the wrong migration. Every one of those was green to the hand that wrote it.

A single Claude that implements and then "switches to Advisor Mode" cannot
reproduce this. It carries the same context, assumptions and blind spots into
the review, and will reliably produce the *shape* of rigorous review — headings,
evidence classifications, "I disagree with this design because…" — while
systematically failing to find what it didn't already know it missed. That is
worse than no review, because it is more confident and harder to distrust.

Therefore:

- `finance-execute` **ends** a task by writing a structured report to
  `docs/handoff/reports/<branch>.md` — stating what it verified, how, and what
  it assumed — and then spawning the `finance-reviewer` subagent with **only**
  that path and the branch name. Findings come back raw, unanswered.
- `finance-review` is invoked in a **fresh context**, given the report plus
  repository and doc access, and explicitly not the implementation conversation.
  It re-derives rather than inherits. Its first instruction is to check whether
  it did the work, and to hand off if it did.
- The skills reference each other but define **no** "switch modes and review
  yourself" flow. There is no such flow to invoke.
- If the project lead wants advice mid-task — a decision, a modal, a trade-off —
  that is an Advisor invocation, a separate consultation, not the Executor
  changing hats. `finance-execute` emits the decision request and waits;
  `finance-review` carries the decision template.

The separation is the path of least resistance in the pack: neither skill
contains the instructions needed to do the other's job.

### Constraint 2 — a skill points at enforcement; it never substitutes for it

The project's own principle is that a rule without a lint, a gate, a test or a
DB constraint behind it is wallpaper. A skill is a doc. It is therefore
structurally incapable of enforcing anything, and a pack that quietly forgets
this becomes a place where controls go to look implemented.

So the skills tell you where the real mechanisms are — `bin/quality` and its 12
steps, the boundary lint, the append-only triggers, the three fixture oracles,
the duty-separation primitives and their differing scopes — and tell you to
bite-prove them rather than trust them. Where a rule exists with no mechanism,
the pack's instruction is to report that as a finding, not to restate the rule
more firmly.

The practical test applied to every line in this pack: does it change what
someone *checks*, or does it only change what they *say*? Lines that only
changed what people say were cut.

### Constraint 3 — ship only skills that have a real consumer

The project's own principle — avoid speculative abstractions; a reusable pattern
needs a second consumer — applies to process artifacts too. Do not produce
twenty separate frameworks, checklists and templates on spec. Most would be
front-loaded structure with no demonstrated use, and a pack people learn to
ignore is worse than a small one they actually follow.

Ship the small set. Anything else emerges when a second real use appears.

This is the same rule `finance-method` states for code — don't front-load a
primitive ahead of its consumer — turned on the pack itself, and it is what
determined the pack's contents. Every piece here has a demonstrated consumer:

- The five skills each cover work that recurs on this project every week.
- `brief-template.md` — five briefs written, stable shape, the most repeated
  artifact on the project.
- `report-template.md` — every brief closes with one, and the reviews have
  repeatedly turned on what the report did and did not say.
- `review-template.md` — the review output, produced on every non-trivial change.
- `decision-template.md` — mid-flight forks have arrived several times, and were
  mishandled at least once by choosing between offered options instead of
  checking whether the fork was decidable from evidence.
- `evidence-checklist.md` — the pre-publication pass, extracted because the same
  four or five checks were being reconstructed from memory each time and
  occasionally skipped.

Nothing else ships. There is no severity framework, no separate reporting
standard, no onboarding doc, no glossary, no command surface — each was
considered and each lacked a second real use.

A consequence worth naming: keeping each `SKILL.md` short is not a separate
aesthetic goal, it is this constraint operating at the paragraph level. A line
that does not change what someone checks is front-loaded structure too. The
material that is genuinely long sits in `references/` and loads only when the
task reaches it.

Five skills is arguably one too many, since `finance-method` and
`finance-context` load together on nearly every task. They stay separate for one
concrete reason: the substrate facts get revised as the codebase moves, and the
method should not be touched when they are.

---

## Which skill, when

| Skill | Loads when | Role |
|---|---|---|
| `finance-method` | Every Brookstone task, both sides | How work is proposed, proved and attacked |
| `finance-context` | Every Brookstone task, both sides | Durable substrate facts — orientation, not conclusions |
| `finance-investigate` | Diagnosing, auditing, confirming a claim | Deriving a finding that survives being checked |
| `finance-execute` | Writing a brief, or working one | The brief as an artifact; the report that closes it |
| `finance-review` | A change or report comes back | Attacking the result, in a fresh context |

`finance-method` and `finance-context` are the base pair. The other three are
role-specific and, by Constraint 1, `finance-execute` and `finance-review`
should not both be active in the same conversation.

## The three invocations of a typical change

**1. Advisor — investigate and brief.** Loads method, context, investigate,
execute. Derives the finding from the repo and the local copy, classifies its
evidence, and emits an implementing-agent brief using
`finance-execute/references/brief-template.md`. Writes no code.

**2. Executor — implement and report.** Fresh context. Loads method, context,
execute. Confirms the brief's premise against the repo before starting, does the
parts in order, produces the watched red, and emits a report using
`finance-execute/references/report-template.md`. Then stops.

**3. Advisor — review.** Fresh context, given the report and repository access,
not the implementation conversation. Loads method, context, review. Verifies
every load-bearing claim against the repo, attacks in the order the skill gives,
and emits a review using `finance-review/references/review-template.md` —
findings plus an explicit statement of what was checked and held.

A fork mid-flight is a fourth invocation, not a mode switch: the Executor emits
a decision request, and an Advisor answers it using
`finance-review/references/decision-template.md`. That template's first
instruction is to establish whether the fork is decidable from evidence, because
most forks presented as judgement calls are not.

## The subagent, and why it is not a mode switch

`.claude/agents/finance-reviewer.md` makes the hand-off mechanical: the executor
writes its report to a file and spawns the reviewer with nothing but the path
and the branch. The subagent has its own context, no Write or Edit, and an
explicit instruction to ignore any framing it was handed and re-derive scope
from the diff.

This is a real context boundary, not a hat change — which is what Constraint 1
asks for. Its honest limit is that the reviewer is spawned *by the thing being
reviewed*. It cannot hide a shortcut, because it reads the repository rather
than the executor's story, but it can be pointed at the wrong wall, since the
executor controls the frame. Passing it only a path is what shrinks that
surface; instructing it to re-derive scope is what covers the rest.

So the subagent is the floor. The `full review` tier — money, migrations, roles
and grants, isolation, gates, fixture oracles — still earns a cold session
started fresh from the report file, and `finance-execute` requires the executor
to say so in its headline when the change is in that tier.

## What the pack deliberately does not do

It does not let a context review its own work. The automatic review after every
task runs in a **separate invocation** — that distinction is the whole of
Constraint 1, and the subagent exists so that taking the correct path is less
effort than taking the wrong one.

It does not carry state — who holds which role, what has shipped, what is done.
State cached in a skill goes stale silently and becomes a source of confident
errors. `finance-context` carries only facts pinned by a test, a migration or a
schema.

It does not assert two rules that are commonly attached to projects like this
and are false here: that every migration must be reversible (two carry
deliberate documented no-op `down()`s, correctly), and that baselines only
shrink (that governs the ratchet baselines specifically, and is itself currently
unenforced). Both are handled explicitly in `finance-context` so a reviewer does
not generate findings against rules the project does not hold.
