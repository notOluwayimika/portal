# `.githooks/pre-push` exits 0 with no gate and no output on two shapes

**Status:** open · **Filed:** 2026-08-21, from the cold review of `feat/docs-only-gate`
**Location:** [.githooks/pre-push:33-39](../../../.githooks/pre-push#L33-L39)
**Predates the docs-only skip and is unchanged by it.** Deliberately not fixed on that branch.

## What happens

The `gated` loop decides whether there is anything to verify at all:

```bash
gated=0
while read -r _local_ref local_sha _remote_ref _remote_sha; do
    [ -n "${local_sha:-}" ] || continue
    [ "$local_sha" = "$z40" ] || gated=1
done <<EOF
$refs
EOF
[ "$gated" = "1" ] || exit 0
```

Two shapes reach `exit 0` without running `bin/quality`, without reaching the release-gate
block, and **without printing anything at all**:

| shape | stdin | why it exits 0 |
| --- | --- | --- |
| **garbage / short line** | `garbage` | `local_sha` expands empty, the line is `continue`d, `gated` stays 0 |
| **delete-only push** | `refs/heads/x 000…0 refs/heads/x <sha>` | `local_sha` is all zeros, `gated` stays 0 |

Measured in this repository:

```
$ printf 'garbage\n' | bash .githooks/pre-push origin url
exit=0            # no output

$ printf 'refs/heads/x 0000000000000000000000000000000000000000 refs/heads/x %s\n' "$(git rev-parse HEAD)" \
    | bash .githooks/pre-push origin url
exit=0            # no output

$ printf '' | bash .githooks/pre-push origin url
exit=0            # no output
```

## Why it is worth a ticket rather than a shrug

A delete-only push has nothing to verify, so exiting 0 is the right ANSWER — the objection is
that it is indistinguishable, in the terminal and in the exit code, from a gate that ran and
passed, and from the garbage-stdin case where the hook did not understand its input at all.
The floor's whole design principle is that a green must say which kind of green it is; that is
the reasoning `.githooks/pre-push:16-18` and `bin/landed:32-36` both turn on, and the
docs-only skip on `feat/docs-only-gate` was built to it.

The one-field case is the sharper of the two: it is not "nothing to do", it is "this input was
not parsed", and the safe direction for an unparsed input is the full gate, not silence.

## Not fixed on `feat/docs-only-gate`

That branch adds the docs-only skip, and its block runs strictly after this one — so neither
shape reaches it and neither is affected by it. Fixing a pre-existing silent exit inside a
branch about something else would put an unreviewed change to the gate's entry conditions under
a diff nobody is reading for that.

## What a fix would have to decide

1. Whether a delete-only push should print a line saying so, or stay silent.
2. Whether an unparseable ref line should fall through to the full gate (the safe direction) or
   refuse the push outright.
3. Whether empty stdin — which `git` does not send in normal operation — is a third case or the
   same as the first.

Any of the three changes when `bin/quality` runs, so each needs its own arm in
`tests/Feature/Quality/DocsOnlyPushCoverageTest.php`'s hook-fixture style, planting the stdin
and asserting on both the exit code and whether the stub gate was invoked.
