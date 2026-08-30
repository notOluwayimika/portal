# `bin/db-exclusive` protects only the branches that contain it

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
