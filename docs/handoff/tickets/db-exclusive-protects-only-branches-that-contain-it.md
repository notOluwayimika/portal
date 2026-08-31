# A repo-local tool protects only the branches that contain it

*(Raised about `bin/db-exclusive`; `bin/board` has since joined it — see the three occurrences below.)*

**Raised:** 2026-08-30, immediately after writing the gate.
**Severity:** ticket — the gate works where it exists; the hole is *where it does not*.
**Fix is named below.** This is not a caveat to be remembered.

## The hole

`bin/db-exclusive` refuses to start a suite while another consumer holds `portal_testing`. It is a
file in the repository, so **it exists only on branches that contain it** — which today is
`docs/verification-lessons` and nothing else.

Every branch cut from `staging` before it lands is unprotected, and **nothing announces that**. It
was met within minutes of being written: a push on `docs/paystack-fee-design` invoked
`bin/db-exclusive --check`, the shell reported `no such file or directory`, the script carried on,
and the push proceeded ungated. It happened to be safe. The gate contributed nothing.

**Worse than absent, in one respect:** the invocation *looked* like protection in the transcript.
Someone reading that command later would reasonably conclude the check ran.

## Three occurrences, which is what makes this an argument rather than an anecdote

All on 2026-08-30/31, all the same shape — a repo-local tool absent from the branch that needed it:

1. **`bin/db-exclusive`, on `docs/paystack-fee-design`.** A push invoked it, the shell reported
   `no such file or directory`, the script carried on, and the push ran ungated. Safe by luck.
2. **`bin/db-exclusive`, on `feat/finance-collation-tripwire`.** That branch's full-suite run —
   2550 tests, used to justify pushing it — was ungated for the same reason. Credible on symptoms
   (contention produces `1213` deadlocks spread across every file, which this did not show), **not on
   a check**.
3. **`bin/board`, on `feat/guardian-merge-command`.** The board-reporting tool was absent from the
   branch the board was being reported from — so the instrument written *because* status came from
   recall could not be run at the moment status was being given. It had to be replaced by an inline
   ref comparison.

The third is the clearest: **the tool for not-trusting-recall was itself unavailable, and nothing
said so except a shell error that a human happened to read.** One instance is an anecdote; three
across two tools in two days is the shape of the problem.

## Why this is the reachability problem again

The tool exists. It is correct. It is not *where it is needed*. That is the third costume of the same
failure this repository met twice on 30 August — a decision document nobody could reach because it
sat on an unpushed branch, and a lesson stranded on a feature branch — and it is worth naming as one
class rather than three incidents.

## The fix, either half of which closes it

1. **Merge `docs/verification-lessons` to `staging`.** Every branch cut afterwards inherits the gate,
   and every existing branch inherits it at its next merge from `staging`. This is the cheap half and
   it is the one to do.
2. **Make the invocation fail loudly when the script is absent**, so an ungated run is never silent:

   ```bash
   [ -x bin/db-exclusive ] || { echo "db-exclusive missing — rebase onto staging"; exit 1; }
   bin/db-exclusive ./vendor/bin/pest
   ```

   Without this, (1) still leaves a window in which old branches run ungated and say nothing.

## What it does not close

`bin/db-exclusive` counts processes rather than taking a lock, so it cannot see a consumer on another
machine or one that starts between the check and the run. That residual is stated in the script
itself and is deliberate — it closes the case that actually bit and claims nothing wider.
