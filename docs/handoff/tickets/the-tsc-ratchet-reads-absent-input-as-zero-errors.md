# The tsc ratchet reads absent input as zero errors, and offers to write the floor down

**Status:** open. Met 31 August during the roster page-ceiling commit, when the script was run with
no argument.

## What it does

`bin/ci-tsc-ratchet.php:18`:

    $outputPath = $argv[1] ?? 'php://stdin';

and `:22`:

    $output = @file_get_contents($outputPath);

With no argument and nothing piped, and equally with a missing or unreadable file — the `@`
suppresses that too — `$output` is an empty string, the error count is 0, and the script prints:

> tsc-ratchet: type errors DECREASED — 0 < baseline 42 (good!)

followed by an invitation to regenerate the baseline, which would write the floor from 42 to 0.

## Why it is a defect rather than a quirk

`.githooks/pre-push` states the house rule about its own docs checker in its own words: every
non-zero exit runs the full gate, because "the safe direction is always to run everything, so an
unanswerable question must never become a skip." This script does the opposite — asked a question it
was given no input for, it answers "good".

## Right-sizing it

It cannot silently pass CI today: `bin/quality` feeds it real `tsc` output, and that path was
re-checked on 31 August (OK, 42 == baseline 42). And a `generate` run in the empty state writes a
floor of 0, which produces a false **red** on the next honest run rather than a hole. So this is a
ticket and not a blocker.

What it is, is a ratchet that can be talked into deleting itself by someone running it by hand to
see what it says — and a gate whose green sometimes means "I was told nothing" is a gate people
learn to read past.

## What closes it

Refuse when the input is absent. An empty read, a missing file and an unreadable file are all
"could not determine" and must exit non-zero rather than count zero. Drop the `@` so the reason is
visible, and require the argument rather than defaulting to stdin — or, if the stdin default is
wanted, detect an empty stdin explicitly.
