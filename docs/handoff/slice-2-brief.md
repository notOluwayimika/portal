# Slice 2 — brief (feasibility-read output; 2026-07-19)

Open slice 2 **fresh** — real financial-calculation feature work, not tail-of-thread. This scope is the
brief; nothing here needs further investigation before building. Read the authoritative repo files first
(same list as `docs/handoff/session-2-start.md`), plus `v10 §28` for the delivered-state reconciliation.

## Verdict: proceed with two small in-slice guards
No un-built Phase-1 primitive is required. Confirmed by tracing real code (not assumed):
- **Idempotency engine NOT needed** — slice 2 uses the manual single-path trigger; the general
  idempotency-key table/middleware (v10 1E) is for webhooks + API replay (Phase 12).
- **`finance_student_accounts` lock anchor NOT reached** — every ledger write is an append-only INSERT
  (`SubledgerPoster::post`); nothing reads a derived balance or locks. The lock anchor is for
  "consume a credit balance exactly once" during payment allocation (Phase 6). No balance-decide-write
  anywhere in `app/Finance/` today (grep: only docblock prose mentions balance/SUM).

## Scope — real multi-line invoicing
- **Multi-line invoice generation**; **F6: total = SUM(lines)**, computed + snapshotted *in* the
  generation transaction, proven with a multi-line test. (Today `GenerateInvoice` takes a single
  caller-supplied `Money $amount` and writes one line — `app/Finance/Actions/GenerateInvoice.php`.)
- **VOID status** — align `InvoiceStatus::Cancelled` → policy's `VOID`. Mechanism already built:
  cancel-by-reversal exists (`CancelInvoice` posts `LedgerEntryType::Reversal` of `total->times(-1)`).
- **Default-exclude-void query scope** on `Invoice` — genuinely unbuilt; void rows excluded from default
  totals, still visible on an audit view. (No status scope on the model today.)
- **Reversing-entry gate test** — void a non-zero invoice → balance unchanged (charge + reversal = 0),
  absent from default totals, present on audit view. (Reversal posting itself already built.)

## Two guards slice 2 MUST add (both in-slice, not separate phases)
1. **Duplicate-invoice guard** in `GenerateInvoice`: in-transaction check "an *active* (issued) invoice
   already exists for this enrollment" → throw. Optional DB backstop: nullable `active_enrollment_key`
   = `student_curriculum_id` while issued, NULL on cancel, unique index (NULLs don't collide).
   ⚠️ A naive `unique(school_id, student_curriculum_id)` is **WRONG** — cancelled invoices are
   append-only and never leave, and policy = repeat bills fresh, so a hard unique blocks legitimate
   re-billing after cancellation. (Same NULL-unique technique Option-B's design specifies for its
   `active_key` — but Option-B is design-only, no landed code to copy.)
2. **Per-invoice void guard** in `CancelInvoice`: `lockForUpdate` on that invoice, or conditional
   `UPDATE … WHERE status = 'issued'`, so two concurrent voids can't double-reverse. Per-row — **NOT**
   the account lock anchor.

## Concurrency proof required — not static reasoning
Both guards were traced statically only. Slice-2 acceptance MUST include bite-proofs under **concurrent**
execution on real MySQL (REPEATABLE READ): two `GenerateInvoice` for the same enrollment → **one**
invoice; two `CancelInvoice` → **one** reversal. Same discipline as the Sequences first-use race — a
read-then-write guard is only as good as its proof under concurrency.

## Rounding is DESCOPED unless slice 2 divides
Multi-line SUM is exact (`Money::plus`) and never rounds. Banker's rounding (remainder-on-final) is only
exercised by **division** (installment splits, %-discounts). The Money VO `split/allocate` op is a pure,
self-contained addition — now permitted (accounting-policy.md §1 is signed) — but **add it only when the
first *dividing* consumer lands**, not speculatively. If slice 2 is generation + void + F6, don't build it.

## Two doc-drifts to fix as slice 2 lands them (not before)
- `App\Support\Money` docblock still says the rounding policy is "unsigned" — it's signed; update when
  the (possibly-descoped) rounding op lands.
- `InvoiceStatus::Cancelled` → `VOID` to match policy vocabulary.

---

## Parked defect (own slice, not slice 2) — tsc ratchet is a false-green
Verified 2026-07-19; corrected from the first-pass numbers.
- Committed `tsc-baseline` = **149** (regenerated *up* from origin 143 on 2026-07-15, `93aaef4`).
  Working tree = **151 in 30 files** → **+2 over the real baseline** (not +8 over the stale 143).
- The ratchet **is** wired (`bin/ci-tsc-ratchet.php` in `lint.yml`, push + PR to staging/main) and
  **trips exit 1 at 151 > 149 when it runs**. So it is not "blind up to 143+". Yet 2 above-baseline
  errors sit in the tree ⇒ effectively a false-green: either the `linter` job is not a *required*
  status check (unverified — no `gh` in env), or errors entered via a non-gated path.
- Tool weakness confirmed: `generate` writes any count (baseline rose 143→149) and `count < baseline`
  only prints "please lower" (exit 0, no auto-lock). "Baselines only shrink" is **unenforced**.
- **Action (own slice):** verify branch-protection requires the `linter` check; then either burn the
  2 (or all) down and regenerate the baseline to the real count, or at minimum ratchet the baseline
  *down* to a true floor and make it fail-and-block. Baseline must equal the real count and the check
  must actually block. Top codes: TS2322×23, TS18046×23, TS7006×19, TS2554×19.
