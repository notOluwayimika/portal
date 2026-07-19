# Brookstone Finance — session handoff (Phase-1 → slice 2)

Read these repo files first — they are the authoritative state, not this summary:
docs/roadmap.md · docs/finance/walking-skeleton-conventions.md · docs/finance-data-ownership.md ·
docs/finance/accounting-policy.md · ADRs 0002–0044 · docs/runbooks/prod-divergence-and-cascade-queries.sql

## Where we are
Phase-1 prerequisites cleared. Finance walking skeleton BUILT and FROZEN as the module template. Decision
backlog cleared. Remaining: build (slice 2) + the eventual first deploy.

## Working method (keep doing this — caught 8+ defects behind green tests)
Agent proposes → review → implement → verification pass that ATTACKS the result, not confirms it.
Bite-prove every gate. Known-positive inventory. Baselines only shrink. "Obviously stale" clusters contain
live bugs (5/22, 2/18). Green over a disabled precondition proves nothing — ask what a pass passes BECAUSE
of. Local validates the query; production produces the number. A rule without a lint/gate/DB-constraint is
wallpaper. Don't open a one-way or template-copying slice at a session tail — build those fresh.

## Confirmed & done
- Template frozen: finance_* prefix, DB-enforced uniform school_id (composite FK), /api/v1/finance,
  @property/@mixin, Money VO wire, append-only ledger (1.4c triggers survived rename). 4 migration paths OK.
- Accounting policy (Brookstone-confirmed, committed): banker's rounding + remainder-on-final-installment;
  unique-only numbering (gaps OK → Sequences kernel correct, NO gap-free work); cancellation = VOID status,
  NEVER SoftDeletes; waiver shows on statement; repeat = billed fresh + manual adjustment.
- Production-verified: S7 access divergence CLEAN (no access lost at eventual column drop, no backfill);
  CASCADE assessment-loss none-detectable within audit coverage (2026-05-22→07-19), pre-window a stated
  blind spot. Scheduler running in prod.

## Invariants (enforced-or-GAP)
F1 finance_ prefix ✅ · F2 school_id present ✅ · F3 child=parent school_id ✅ · F4 append-only ✅ ·
F5 Finance owns truth ✅ access-only, GAP: read-only-Contract rule pending 2nd Contract · F6 total=SUM(lines)
GAP: pending slice 2.

## NEXT WORK — slice 2: multi-line invoices (fresh feature construction)
Build real invoice generation on the frozen template. Lands F6 (total=SUM(lines), computed+snapshotted at
creation, multi-line test) AND the two void-safety gates: (a) default scope excludes VOID, (b) void posts a
reversing ledger entry; gate test = void a non-zero invoice → balance unchanged, void absent from default
totals, present on audit view. Discover conventions by building, like the skeleton did. STOP before it
becomes a template others copy.

## Blocked / later (NOT slice-2 blockers)
- S7 column drop: divergence clean BUT parity soak (RBAC_PARITY_SOAK) still its own gate + drop is
  post-deploy, one-way, STOP-before-flip. "Divergence clean" ≠ "drop ready."
- Enrollment Option-B (episodic re-key, 12 readers): deadline is "before repeat workflow," NOT before
  Finance. Fresh session, one-way flip, STOP.
- 1.4e event bus: blocked on Option-B.
- First Phase-1 deploy: large, all migrations land together; pre-flight = re-verify students.school_id 0
  nulls in prod + verify every FK-dropping down() re-upgrades (found-once bug).
- Cleanup: 422/400 convention; resend-invitation 400 (treat with suspicion — hid behind a dead method).

## Critical-path truth
Engineering is no longer the bottleneck. Decisions cleared, foundation frozen, prod numbers green. Remaining
is deliberate fresh-session building + the deploy. Code after each unblock is hours; the asks were the weeks,
and they're answered.
