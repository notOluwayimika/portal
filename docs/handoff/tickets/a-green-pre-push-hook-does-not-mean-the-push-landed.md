# TICKET — a green pre-push hook does not mean the push landed

**Status:** open. Raised by `docs/two-toast-libraries` (PR #255), where two consecutive pushes printed
`✓ quality: PASS` and left the branch absent from the remote. The transport cause has a local
workaround; the reason it was *invisible* does not, and that is what this ticket is about.

## What happened, in order

1. `git push` opens the SSH connection to the remote **before** running `.git/hooks/pre-push`.
2. The hook runs `bin/quality` — fifteen steps, roughly ten minutes on this repository.
3. `ssh.github.com:443` drops the connection as idle during those ten minutes.
4. The hook finishes and prints `✓ quality: PASS`.
5. `git push` then tries to use a connection that is gone:
   `Connection to ssh.github.com closed by remote host`, exit **141** (SIGPIPE).

The branch was not on the remote. Twice.

## Why it read as success

**The `✓ quality: PASS` line is printed by the hook, not by the push.** It appears whether the push
subsequently succeeds, fails, or dies on the transport. A reader who watched fifteen steps go green
and saw a `PASS` at the end has every reason to believe the branch is on the remote, and the terminal
gives no contrary signal beyond an exit code nobody reads when the last visible line said PASS.

This is the same shape as the other false-greens already recorded in this repository —
`lint-changed` reporting "no changed files" on an uncommitted tree, the `tsc` ratchet passing a
net-zero swap of one error for another — and it belongs in the same list. A gate that reports on
itself rather than on the outcome is a gate you have to check behind.

## Where it actually bites

Not on a feature branch, where the next command notices. On the **release path**: promoting to `main`
is a push through this same hook, after `bin/quality-promote` has stamped `.quality-promote-ok` with
one exact sha and the hook has required a fast-forward merge. A silent transport failure there leaves
somebody believing `main` moved when it did not, with a stamp on disk saying the release was blessed.
`main` is not something anyone re-checks by reflex.

## The two remedies, and they are not the same remedy

**Transport.** The connection dies because it idles. Fixed for one push with:

```
GIT_SSH_COMMAND='ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=60 -o TCPKeepAlive=yes' git push …
```

and permanently by adding those three options to the existing `Host github.com` block in
`~/.ssh/config`, which already forces `HostName ssh.github.com` / `Port 443` to work around the
earlier port-22 drops. That is machine configuration on one workstation. It is not in this repository,
it does not travel with a clone, and the next person to push from a different machine meets the same
failure with the same misleading output.

**Detection.** Independent of the transport, and the part worth keeping: **after any push, read the
remote back.** The remote's own answer is the only evidence that a push landed.

```
git ls-remote --heads origin <branch>
```

and compare the sha to `git rev-parse HEAD`. For a release, the same read against `main`. This is
cheap, it is the same discipline as ADR 0052 — verify by shape, not by exit code — and it does not
depend on anybody's `~/.ssh/config`.

## Not proposed here

Whether the hook should print anything at all when it cannot know the outcome; whether `bin/quality`
should run before `git push` rather than inside its hook so the connection is opened after the ten
minutes rather than before; and whether the remote read-back should be automated into a wrapper
script rather than left as a habit. All three are real options and none of them is settled. What is
settled is that `✓ quality: PASS` is not evidence of a push, and until something changes, the push is
verified by reading the remote or it is not verified.
