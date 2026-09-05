# Implementation report — four findings from the void-approval investigation

## Headline

**Done, with deviations, after a cold review returned five findings.** Four tickets recording what a
read-only investigation into waiving Executive Director approval for a pre-release correction turned
up, none of which depends on that decision being made. Branch
`docs/four-findings-from-the-void-investigation`, off `staging` @ `ca8dbc45`. Docs only — five new
files, no code, no gate, no fixture. Not pushed, no PR.

**Tier: TARGETED. This corrects a LIGHT call made on the first version of this commit.**

The original argument was "four documentation files, no executable path touched". That was wrong, and
the correction is the reviewer's, not the implementing side's: **a docs commit whose entire product
is CLAIMS ABOUT THE REPO is not a copy edit.** Every sentence in these tickets is an assertion that
must be true of the tree, four of them turned out not to be, and a light pass would have found none
of them. **The tier follows the kind of claim, not the file extension.**

## Deviations from the brief

**One, and it changed 12 citations.** The brief said every `file:LINE` comes from the investigation
and to re-derive anything uncertain. It did not say what FORM a citation must take. My first draft
used repo-relative paths on first mention and bare basenames afterwards —
`app/Finance/Services/VoidEligibility.php:18` once, then `VoidEligibility.php:26`. A machine check
found **7 of 26** unresolvable that way, plus 5 bare `:NN` continuations. All 12 were rewritten to
repo-relative paths.

**The rule I formed, stated as a rule so it can be checked:** *a citation that resolves only because
the reader remembers the previous sentence is not a citation.* The tickets' whole value is sending
the next person to the right line, and a basename is ambiguous the moment two files share it. I
believe this is right; it is also the sort of general rule the template warns about, so it is stated
rather than applied silently.

**Not a deviation, but a judgement recorded:** the four tickets are left **hand-formatted, not
prettier-formatted**. Measured first — 10 of 12 sampled existing tickets fail `prettier --check`, and
no gate checks `docs/` — so formatting these four would depart from the corpus rather than conform
to it.

## Contradictions of the premise

**None of the four findings contradicted the brief.** The brief described each accurately and every
one reproduced.

**One number in the brief was stale, and it was stale for a good reason.** It said regenerating the
access map adds 56 routes, of which 2 were mine. That was true on the pre-merge tree. On `ca8dbc45`
the returned-bills commit has landed and its two entries are in the fixture, so the drift is now
exactly **54**. Re-derived, not carried; the ticket carries 54 and says why the 56 existed.

**One thing the investigation established that the brief did not claim, and it matters to ticket 2:**
the state that ticket describes is reachable only through the submit → approve window. The
combination `reviewed_at IS NOT NULL AND returned_at IS NOT NULL` is unreachable in both directions,
but nothing prevents an ALLOCATION landing in that window — that asymmetry is the whole ticket.

## What changed

| file | lines | what it does |
| --- | --- | --- |
| `docs/handoff/tickets/void-eligibility-docblock-contradicts-its-own-code.md` | 69 | Records that `VoidEligibility.php:18` calls the check advisory while the submit path throws, and names which of the two docblocks is correct. |
| `docs/handoff/tickets/a-late-allocation-strands-a-void-request-forever.md` | 110 | Records the stranded-request trap in full, and the two remedies, neither chosen. |
| `docs/handoff/tickets/nothing-pins-the-single-writer-of-invoice-void.md` | 98 | Records that one writer of `InvoiceStatus::Void` exists and nothing asserts it, and why the pin is a precondition of the Brookstone correction work. |
| `docs/handoff/tickets/fifty-four-routes-are-missing-from-the-access-map.md` | 97 | Records the 54-route drift, its deliberate structural cause, and why regenerating wholesale is the trap. |

Plus this report, which ships in the same commit. **Five files.** An earlier version of this
headline said "four new files, 374 insertions" while the commit contained five and 628 — the report
counted the tickets and forgot itself. The per-ticket line counts were exact; the total was not.

No code file, no test, no fixture, no migration, no route.

## Proof

### The single-writer claim (ticket 3) — the denominator, stated

```
  --- re-derive: every writer of InvoiceStatus::Void in app/ ---
    app/Finance/Actions/ApproveVoidRequest.php:76:                'status' => InvoiceStatus::Void,
  --- and the EXAMINED denominator for that claim ---
    php files under app/ searched: 632
    total InvoiceStatus::Void occurrences (all forms): 6
```

All six, classified:

```
  app/Finance/Models/Invoice.php:203:        return $this->status === InvoiceStatus::Void;
  app/Finance/Models/Invoice.php:217:        return $query->where('status', '!=', InvoiceStatus::Void->value);
  app/Finance/Actions/ApproveVoidRequest.php:76:                'status' => InvoiceStatus::Void,
  app/Finance/Actions/ReturnInvoice.php:174:            if ($locked->status === InvoiceStatus::Void) {
  app/Finance/Actions/ApproveInvoice.php:155:            if ($locked->status === InvoiceStatus::Void) {
  app/Finance/Services/InvoiceSettlement.php:65:        $isVoid = $invoice->status === InvoiceStatus::Void;
```

**Expected:** one write, the rest comparisons. **Observed:** exactly that — 1 write, 5 comparisons.

**And the absence claim, which is the load-bearing half.** "Nothing asserts it" was checked by
looking for the enforcement I would expect and showing it absent:

```
  grep -rn "InvoiceStatus::Void\|only writer\|single writer" tests/Arch/*.php
  (blank = no arch test pins it)
```

`tests/Arch/` holds 11 files; none names it. This is the claim most worth attacking.

### The 54-route drift (ticket 4) — regenerated, diffed, restored

```
  migrate+seed exit: 0
  rbac:sync exit: 0
route-access-map.json written (437 routes).
  RE-DERIVED on ca8dbc45: committed fixture 383 keys -> regenerated 437 keys
  ADDED (the drift) : 54
  REMOVED           : 0
  CHANGED           : 0
  my two routes in the ADDED set now? []  (expect [] — they were committed)
  by method: {'DELETE': 2, 'GET': 31, 'PATCH': 1, 'POST': 17, 'PUT': 3}
  of the drift, unauthenticated routes: 1
  fixture restored: yes, byte-identical to HEAD
  (tree clean)
```

**Expected:** the drift is 54 now that two of the former 56 have landed. **Observed:** 54, with 0
removed and 0 changed — so the fixture is a strict subset of the live route set, which is exactly the
shape the parity test's asymmetry produces.

### The holder sets (tickets 1 and 2) — the map executed, not grepped

```
roles in the EXPANDED map: 15
HOLDERS of finance.invoice.void-request.approve:  executive_director   (total: 1)
HOLDERS of finance.invoice.void-request.reject:   executive_director
HOLDERS of finance.invoice.void-request.submit:   accounts_officer
```

```
 finance.invoice.void-request.approve terminal=approve excludedFromSuperAdminBypass=true
 finance.invoice.void-request.reject  terminal=reject  excludedFromSuperAdminBypass=true
 finance.invoice.void-request.submit  terminal=submit  excludedFromSuperAdminBypass=false
```

**Expected:** if the checker is not the Executive Director, ticket 2's central sentence is wrong.
**Observed:** it is the Executive Director, alone, and `super_admin` is excluded by ADR 0040 — so the
sentence "the only person who can unblock it is the Executive Director" holds.

### Citation resolution — the instrument the repo does not have

```
  EXAMINED 34 distinct path:LINE citations across 4 tickets — RESOLVED 34, UNRESOLVED 0
  exit: 0
  bare forms remaining: 0
```

Run twice: before the commit, and again on the committed tree. First run reported `RESOLVED 19,
UNRESOLVED 7`, which is what produced the deviation above.

**Run again over all five new files, this report included:**

```
    9 citations, 0 unresolved   void-eligibility-docblock-contradicts-its-own-code.md
    9 citations, 0 unresolved   a-late-allocation-strands-a-void-request-forever.md
   13 citations, 0 unresolved   nothing-pins-the-single-writer-of-invoice-void.md
    3 citations, 0 unresolved   fifty-four-routes-are-missing-from-the-access-map.md
   14 citations, 3 unresolved   docs-four-findings-from-the-void-investigation.md

  EXAMINED 48 distinct path:LINE citations across 5 files — RESOLVED 45, UNRESOLVED 3
```

**The three unresolved are all in this report and all three are deliberate specimens**, kept rather
than sanitised because removing them would remove the evidence:

- `VoidEligibility.php:18` and `VoidEligibility.php:26` — the bare-basename form quoted in
  "Deviations" as an example of what was WRONG. They are unresolvable by construction; that is the
  point being made.
- `app/Finance/Actions/ThisFileDoesNotExist.php:99999` — the planted mutation, quoted verbatim in
  "The watched red".

**So the tickets themselves carry 34 citations and 0 unresolved**, which is the number that matters
for a reader following them into the code.

**AFTER THE COLD-REVIEW AMEND that number is 72, still 0 unresolved** — the corrections added
citations and every bare `:NN` continuation was expanded to a repo-relative path, applying the rule
this report states under "Deviations" rather than arguing an exception for line continuations.

**AND THE NUMBER WAS NEVER EVIDENCE OF ACCURACY, which finding 2 proved.** One of the original 34
resolved cleanly to `database/migrations/2026_07_23_120000_create_finance_credit_notes.php:72-73` —
a line that exists and names the trigger it was cited for — while the sentence built on it was false,
because a later migration drops that trigger. A resolution check proves a line exists. It cannot
prove the claim about it is true, and stating "34 resolved / 0 unresolved" without that caveat
invited exactly the confidence it had not earned.

### The docs-adjacent tests

```
  pest exit: 0
  result=passed tests=55 passed=55 assertions=269 failed=0
```

`NothingReadsDocumentationTest`, `DocsOnlyPushCoverageTest`, `CitationLintCoverageTest`.

## The watched red

**The mutation was aimed at the GATE, not at the code**, because this commit contains no code. The
question worth proving was whether `bin/ci-citation-lint.php` examines these files at all.

Planted, in ticket 3:

```
`app/Finance/Actions/ThisFileDoesNotExist.php:99999` is the only place
```

```
  condition planted? 1 occurrence
  citation-lint exit: 0
citation-lint: OK — no new citation violations (164 baselined key(s), 181 citation(s)).
  restored md5 matches: yes
```

**Expected:** a red naming the unresolvable citation. **Observed: exit 0, and the identical 181** —
byte-for-byte the count reported before these four files existed.

**I could not produce a red, and that is the finding rather than a skipped formality.** `docs/` is
deliberately outside `SCANNED_DIRS` (`bin/ci-citation-lint.php:212`), for a measured reason its
header gives at `:90-102`: including docs/ would contribute 1,177 keys and 1,392 citations, seven and
a half times the rest of the tree. So the lint's green is real and it is about other files. That is
why this report states its own citation count — the number is the only coverage these files have.

Restored, md5 identical.

## Findings returned by cold review, and what this amend did

**Findings are not resolved by the side that made the error**, so each is recorded here with the
reviewer's own wording for what was wrong, and what the amend did about it. All five were **returned
raw and unanswered** before any edit was made.

The reviewer's tier call — *"TARGETED, not the LIGHT the report claims… a docs commit whose entire
product is claims about the repo is not a copy edit"* — is accepted and is now this report's stated
tier.

### 1 — *"Ticket 2 asserts an absence of test coverage that the tree refutes"* (fix) — ACTED ON

The ticket said the closing test *"is the one no arm currently has"* and that the state is
*"invisible in tests because a test submits and approves in the same breath."* Both false:
`tests/Feature/Finance/FinanceApiAcceptanceTest.php:933` (VOID PROOF 5) submits, allocates and
asserts the refusal.

**Amended, and NARROWED rather than deleted**, because the residual gap is real and I re-derived its
exact size: PROOF 5 makes **one** approve call and **zero** second-submit attempts, so neither
permanence nor the held slot is asserted for the allocation case; the held-slot half exists only for
the credit-note case at `tests/Feature/Finance/FinanceApiAcceptanceTest.php:975-977`. "What would
close it" now names PROOF 5 as the arm that EXISTS and states the two assertions it must gain.

### 2 — *"the monotonicity table cites a trigger a later migration drops"* (fix) — ACTED ON, and it was the serious one

The table claimed `finance_credit_notes` carries `_no_update` and `_no_delete` triggers and that the
credit-note limb is database-enforced.
`database/migrations/2026_07_25_120000_finance_credit_note_maker_checker.php:68` drops
`finance_credit_notes_no_update` six weeks after it is created.

**Re-derived from the replacement guard's body, not its name.** Answer to "does anything at the
database level prevent `UPDATE finance_credit_notes SET status='submitted' WHERE status='approved'`":
**no.** The guard's immutability arm does not list `status` — its SIGNAL text says
*"only status/decided_by/decided_at/rejection_reason may change"* — and its ceiling arm is
conditioned on `NEW.status = 'approved'`, so a move OUT of approved does not reach it. Confirmed
against a fully migrated database: `finance_credit_notes_no_update` **ABSENT**,
`finance_credit_notes_no_delete` **PRESENT**.

The monotonicity CONCLUSION survives and now rests where it actually lives — `CreditNote::TRANSITIONS`
(`'approved' => []`) and `transitionTo()`'s `\DomainException` — labelled as PHP.

The ticket also gained the reusable paragraph: **this error passed the commit's own citation check
because the cited line exists and names the trigger. That is resolvability, not accuracy.** No gate
is proposed, and the ticket says why.

### 3 — *"names two sites and prescribes a one-line fix; five carry the same wrong description"* (fix) — ACTED ON, and the number is seven

**Derived, not accepted.** The classified grep found **7** sites in the wrong bucket, not five —
including two TEST COMMENTS on the acceptance proofs themselves, which the ticket now calls out
separately because the proof beneath such a comment is the evidence. Full classification and
denominator (34 hits, 7 wrong / 3 correct / 17 unrelated / 7 this commit's own) is in the ticket, and
the closure instruction is now **the grep**, not the file.

### 4 — *"names the wrong sibling and claims a novelty the corpus already holds"* (ticket) — ACTED ON

The "with a number attached" sentence pointed at the ticket without a number. The numbered twin is
`docs/handoff/tickets/route-middleware-baseline-is-67-routes-stale.md` (68 additions, zero removals,
67 pre-existing, raised 2026-08-31), and a third sibling exists —
`docs/handoff/tickets/duplicate-check-route-is-in-neither-route-oracle.md`, the intersection with
n = 1. All four are now named in a table with what each measures.

### 5 — *"'caught by review or not at all' overlooks a partial runtime detector"* (ticket) — ACTED ON, with one correction to the finding

Accepted: `app/Finance/Console/AuditLedgerCoherence.php:215-216` check I4 catches a bypass that skips
the reversal.

**The reviewer left open whether anything schedules it, and asked for it to be measured. It IS
scheduled** — `routes/console.php:127`, `->daily()`. That strengthens the concession rather than
weakening it, so the ticket now says so, while keeping the three qualifications that matter: I4 is
detective not preventive (absent from `bin/quality` and `.githooks/pre-push`, measured as zero
occurrences in each), it sees only the money limb, and all four request-table guards stay blind.

### The nomination was right about the class and wrong about the file

This report's first version nominated **ticket 3's assertion of absence** as the claim most worth
attacking. The reviewer attacked it and it **survived** — 632 files, 6 occurrences, 1 write, no arch
test, verified independently.

The false assertion of absence was in a **different** ticket: the late-allocation one, claiming no
test arm existed. So the nomination identified the right KIND of error and pointed at the wrong file,
which is worth recording because it is the more common shape — the claim you are nervous about gets
checked, and the one you are confident about does not.

## Database observations

`portal_testing` was `migrate:fresh --seed --force` then `rbac:sync`, to derive the access map
against a synced grant set. No production copy, no dev database, no drive database was touched by
this commit's work.

| | before | after |
| --- | --- | --- |
| roles in the expanded grants map | 15 | 15 (read only) |
| access-map keys, committed fixture | 383 | 383 (restored byte-identical) |
| access-map keys, regenerated | — | 437 |

`bin/db-exclusive` held the database for every command that touched it.

## The first push pays the full gate — checked, not assumed

`bin/is-docs-only-push` refuses when the base is all zeros, which is what a push of a branch the
remote does not have supplies. Read at `bin/is-docs-only-push:137-140`, then run:

```
$ bin/is-docs-only-push 0000000000000000000000000000000000000000 <head>
is-docs-only-push: base is all zeros — a new branch has no base to diff against
  exit: 1

$ bin/is-docs-only-push ca8dbc45 <head>
docs/handoff/reports/docs-four-findings-from-the-void-investigation.md
docs/handoff/tickets/a-late-allocation-strands-a-void-request-forever.md
docs/handoff/tickets/fifty-four-routes-are-missing-from-the-access-map.md
docs/handoff/tickets/nothing-pins-the-single-writer-of-invoice-void.md
docs/handoff/tickets/void-eligibility-docblock-contradicts-its-own-code.md
  exit: 0
```

`.githooks/pre-push:80-98` delegates the decision to that script and its header states the rule that
makes the exit code matter: *"every non-zero must lead to the full gate and nothing here may exit 0"*
on doubt. So the content IS docs-only — the second run proves it — and the first push of this branch
still runs everything, because the remote has no base to diff against. Expected, not a fault.

## Not done

- **The four tickets propose no fix and open no work.** Tickets 2 and 4 record options without
  choosing, as briefed; ticket 3 names the mechanism that would close it but does not build it.
- **The arch test ticket 3 asks for is not written here.** It is named as a precondition of the
  Brookstone correction work, not delivered.
- **The `VoidEligibility.php:18` docblock is not corrected**, though the correct wording is stated in
  ticket 1. This commit is docs-only by instruction; the one-line source fix is a separate change.
- **Resolved by choosing:** whether to prettier-format the four tickets. Measured the corpus (10 of
  12 unformatted), chose to match it. If the project's intent is the opposite, this is the line to
  reverse.
- **Not verified:** that the 54 drifted routes are each correctly gated. The ticket claims only that
  their access sets are unreviewed, not that any is wrong. Establishing the latter is 54 separate
  questions and is the work the ticket exists to schedule.

## Findings raised, not fixed

- `app/Finance/Services/VoidEligibility.php:18` — docblock says "advisory at submit"; the submit path
  throws. **ticket** (filed as ticket 1 of this commit).
- `app/Finance/Actions/ApproveVoidRequest.php:65-67` — a late allocation strands a submitted request
  permanently and holds the invoice's only open slot. **fix** (filed as ticket 2).
- `app/Finance/Actions/ApproveVoidRequest.php:76` — sole writer of `InvoiceStatus::Void`, unasserted.
  **fix**, and a precondition of the correction work (filed as ticket 3).
- `tests/Feature/Rbac/RouteAccessParityTest.php:18-22` — the asymmetry has no ratchet; drift is 54.
  **ticket** (filed as ticket 4).
- `app/Finance/Actions/ApproveVoidRequest.php:82-102` — the void reversal is dated to the ORIGINAL
  charge's period, deliberately. Under a waived-approval correction path this rewrites closed prior
  periods on every correction. **Raised in the investigation, not filed as a ticket**, because it is
  an argument about the proposal rather than a defect in the tree.
- A void emits **no activity-log tier at all** — no key in `config/activity_log_severity.php` or
  `config/activity_log_sensitive.php`, and neither `ApproveVoidRequest` nor the models emit one.
  **Raised, not filed**, for the same reason. It becomes a defect only if voids become ordinary.
