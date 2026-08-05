# 0041 — Ratchet baselines are content-keyed

**Status:** Accepted · implemented (1.2d authz lint; 1.5a boundary lint; Larastan baseline)

## Context

CI became a real gate over a codebase with pre-existing debt by freezing known
findings in committed baselines and failing only on NEW findings. A baseline
needs a key. Line-number keys go stale on every unrelated edit (constant false
churn); file-level keys are far too broad (a second violation in a baselined
file would pass forever).

## Decision

- **`authz-lint`** entries are keyed `file + trimmed line content`.
- **`boundary-lint`** entries are keyed `rule + file + trimmed line content`.
- **Larastan** uses PHPStan's native baseline: `message + identifier + path +
  count`, with unmatched (fixed) entries failing the run — the tightest form.
- **`duty-separation-baseline.txt`** entries are keyed
  `school_id | user_id | checker | maker`. This is the first baseline here whose
  key is a tuple over DATABASE STATE rather than over file content, so the
  staleness argument above inverts: the key cannot churn on an unrelated edit,
  but it can go stale when nothing in the repository changed at all — a grant
  revoked in the database resolves a finding with no diff anywhere.

## Consequences

- A differently-worded violation anywhere — including in a file that already
  has baselined entries — produces a new key and **fails CI**. The feared
  file-keyed hole does not exist.
- **Known residual (accepted):** in the two lint baselines, an *exact
  character-for-character duplicate* of a baselined line, in the same file
  under the same rule, dedupes against the existing entry and passes silently.
  Larastan is unaffected (count-keyed). If this residual ever bites, the
  strengthening is per-entry occurrence counts, phpstan-style.
- Baselines may only shrink; the gates report removable entries when a
  baselined finding is fixed. Widening a baseline to get a PR green is never
  acceptable — a deliberate, documented, expiring exception requires review.
- The duty-separation baseline is read by the nightly scheduled task, not by
  `bin/quality` — the only baseline of the six whose consumer is a cron. Its
  stale-entry report therefore reaches a log, not a pull request. What makes it
  a gated baseline rather than a file nobody validates is ARM 7 of
  `DutySeparationBaselineTest`, which reads the committed file in the suite and
  asserts its shape and that no `finance.` line was added to it.
- One class of finding may never be baselined here at all: a `finance.` finding
  is refused by `AuditDutySeparation::NEVER_BASELINEABLE` before the file is
  read. A baseline is an editable file, and a control that can be silenced by
  editing a file is exactly as strong as whoever last edited it.
- Boundary-baseline entries carry documented expiries (e.g. the `finance_*` reads
  expire at ADR 0030; the `users.school_id` fallbacks at the §5.3/§7.1 column
  drop — see ADR 0042), including nature notes so burn-down work isn't
  misdirected (the `SuperAdmin/AdminController` entry is a legacy-column
  maintenance *write*, not a context-read fallback).
