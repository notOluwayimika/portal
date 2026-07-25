# Maker-checker: the two-instance diff (credit-note vs invoice-void)

**Status:** input to the "extract a generic maker-checker engine?" decision (the
prospective ADR 0009). **Not** a decision itself — it is the evidence the Rule of
Three asks for. Written after Ph3b (invoice void) shipped as the *second* instance
of the pattern Ph3 (credit-note issuance) established.

The question this document exists to answer: **now that the template has been
instantiated twice, is the shared shape stable enough to extract, or does the
divergence between the two instances mean an abstraction would be premature?**

## The two instances

| | Ph3 — credit note | Ph3b — invoice void |
|---|---|---|
| Document | `finance_credit_notes` | `finance_void_requests` |
| Maker action | `SubmitCreditNote` | `SubmitVoidRequest` |
| Checker approve | `ApproveCreditNote` | `ApproveVoidRequest` |
| Checker reject | `RejectCreditNote` | `RejectVoidRequest` |
| Policy | `CreditNotePolicy` | `VoidRequestPolicy` |
| Permissions | `finance.credit-note.{submit,approve,reject}` | `finance.invoice.void-request.{submit,approve,reject}` |
| Status enum | `CreditNoteStatus` | `VoidRequestStatus` |
| Money effect of approve | posts a compensating **credit** (≤ invoice total, ceiling-guarded) | posts a **reversal** (= full invoice total) + flips the invoice to `void` |
| Money effect of reject | none | none |

## What is IDENTICAL across both (the extraction candidates)

These pieces are line-for-line structural twins. If a generic engine is built,
this is its surface:

1. **Lifecycle columns.** `submitted_by` / `decided_by` / `decided_at` /
   `rejection_reason`, `status` defaulting to `submitted`.
2. **The DB CHECK.** `submitted_by IS NULL OR decided_by IS NULL OR submitted_by
   <> decided_by` — maker ≠ checker made structurally unrepresentable, with the
   NULL guard for unknown-maker rows. Identical clause, identical name suffix
   (`_maker_ne_checker`).
3. **The status machine.** A `TRANSITIONS` map with `submitted → {approved,
   rejected}` and both decision states **terminal**; `canTransitionTo` /
   `transitionTo(to, checkerId, reason)` with an illegal-transition throw and a
   reason-required-on-reject guard. Identical.
4. **The immutability trigger shape.** DELETE denied; UPDATE guarded to permit
   ONLY the decision columns (money/identity frozen). Same "relaxed append-only"
   as `finance_invoices`.
5. **The Policy.** `approve`/`reject` = `can(<ability>) && isNotTheMaker`, with
   `isNotTheMaker` comparing `submitted_by` to `user->id` **as strings**
   (fail-safe on a type mismatch) and admitting a NULL maker. Byte-identical bar
   the ability strings and the model type-hint.
6. **The Kernel wiring — untouched, and this is the strongest signal.**
   `ApprovalAbility::matchingMakerFor`, `isExcludedFromSuperAdminBypass`, the
   `Gate::before` bypass exclusion, and the `SyncRolePermissionsRequest` grant
   guard all recognised `finance.invoice.void-request.*` **by convention, with
   zero changes**. The `.submit`/`.approve`/`.reject` terminal-segment naming was
   the entire integration contract. This half of the pattern is *already*
   abstracted — the convention IS the engine for the authorization dimension.
7. **The Resource + queue.** Both resources carry a `type` discriminator and
   server-computed `can_approve`/`can_reject`; the frontend merges the two
   pending feeds into one datatable. The unified queue is the proof they present
   identically.
8. **The controller skeleton.** `submit` (permission-gated route) → `approve` /
   `reject` (`Gate::authorize` + delegate). Same four methods, same shape.

## What DIVERGED (why extraction is not obviously free)

The differences are **all in the money semantics of approval** — the one place
the two instances genuinely differ:

1. **What approval DOES.** Credit-note approval posts a compensating credit and
   is bounded by a **ceiling** (Σ approved credits ≤ invoice total), enforced at
   the approval transition and backstopped by an INSERT guard. Void approval
   posts a **full-total reversal** and additionally **mutates a foreign
   aggregate** — it flips the invoice's `status` to `void` and stamps its cancel
   metadata. A credit note never touches the invoice row; a void's whole point is
   to.
2. **Preconditions.** Void has a `VoidEligibility` gate (no allocated payment, no
   approved credit note) that is **advisory at submit, authoritative at approval
   under the invoice-row lock** — a payment can land between submit and approve.
   Credit-note has no equivalent moving precondition; its ceiling is computed
   from append-only history that only grows.
3. **The concurrency lock target.** Void approval must
   `lockForUpdate` the **invoice** first (it is voiding it, and a second approval
   must find it already void). Credit-note approval locks around its own ceiling
   computation. Different lock objects, different invariants.
4. **The uniqueness constraint.** Void needs "one OPEN request per invoice" — a
   generated-column `open_key` + UNIQUE. Credit-note has no such constraint (many
   credit notes per invoice are legal). This is a per-instance schema decision,
   not shared.
5. **No insert guard on void.** A void request carries no money of its own, so a
   raw approved-insert forges an audit row but moves nothing (only
   `ApproveVoidRequest` posts the reversal). Credit-note DOES need an INSERT guard
   (its ceiling must hold against raw writes). The trigger *set* differs by one.
6. **The reject side-effect surface.** Both reject cleanly, but "clean" means
   different things: credit-note reject touches nothing; void reject also touches
   nothing but had to be reasoned about against an invoice that is *still live*.

## Reading of the evidence

- The **authorization + lifecycle + immutability + Policy** dimensions are
  identical and, for the Kernel half, **already extracted via convention**. An
  engine covering these would remove real duplication (a `HasMakerCheckerLifecycle`
  trait + a base Policy + a shared migration-builder helper are the obvious
  shapes) and would be low-risk, because the convention already proved these
  pieces are stable across two instances.
- The **money semantics of approval** are irreducibly per-instance. Any engine
  must leave the "what does approval DO" body as a supplied hook (an Action the
  engine calls inside its transaction/lock scaffolding), not try to generalise
  it. The ceiling-vs-reversal, the foreign-aggregate mutation, the moving
  precondition, and the lock target are not parameters of one abstraction — they
  are the domain.

## Recommendation to the ADR

**Extract the convention-adjacent scaffolding; do NOT build a money engine.**
Concretely, if/when a third instance appears (the Rule of Three is now at two):

- A trait/base for the lifecycle columns + `TRANSITIONS` + `canTransitionTo` /
  `transitionTo`.
- A migration helper that emits the CHECK, the DELETE-deny + decision-only UPDATE
  guard, and (optionally) the generated-column open-request UNIQUE.
- A base Policy providing `approve`/`reject` = `can && isNotTheMaker`.

Leave the approval Action per-instance. The two money bodies here share almost no
code and would only be coupled by a false abstraction. The Kernel convention
already carries the part that genuinely wanted to be shared, and it needed no
engine to do it — which is itself the argument for restraint.

**Trigger for revisiting:** a third maker-checker instance. Until then, two
instances is exactly the Rule-of-Three "not yet" — the duplication is visible,
catalogued here, and cheap to carry.
