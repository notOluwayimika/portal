# The §24 condition-3 gate accepts any string but one — and nothing runs it

**Raised:** 2026-09-02 · **From:** reading the classification consumer while writing the census tickets · **Severity:** ticket (blocks the `AUTHZ_ENFORCE` flip)

## Two failures, and they compound

**1. The gate accepts any string but one.** `app/Console/Commands/AuthzObservations.php:118`:

```php
$rows = $rows->filter(fn ($r) => $r['classification'] === 'UNCLASSIFIED')->values();
```

That is the whole test. The value comes from `:113` —
`$classifications[$key]['classification'] ?? 'UNCLASSIFIED'` — so a class with **no entry** reads
`UNCLASSIFIED` and fails, and a class with **any entry at all** passes. `expected` passes.
`regression` passes. So do `expcted`, `tbd`, `""` and `0`. The vocabulary is defined in the
artifact's `_readme` and enforced nowhere.

**2. Nothing invokes it.** `authz:observations --unclassified` appears in **no** automated run —
not `bin/quality`, not `.githooks/pre-push`. `bin/quality` runs `authz-lint` (`:212`),
`boundary-lint` (`:215`) and the arch group (`:283`); this is not among them. It is a command
somebody has to remember to type.

**Together they are not two small problems.** A check that runs and is permissive is a **lax
control** — it lets bad input through, and you can at least see it running. A permissive check that
**nothing runs** is **not a control at all**, and this one is documented as **condition 3 of the
`AUTHZ_ENFORCE` flip**. The flip's stated precondition is, mechanically, nothing: no automated caller,
and no verification of the input if one appeared.

And it fails in the direction that reads as success. When somebody does run it, they see
*"every observed denial class is classified (§24 condition 3 input satisfied for this window)"*
(`:124`) — a claim considerably stronger than the code behind it.

## Order of work — wire it FIRST

**The wiring is the cheaper fix and the one that makes the other worth making.** Pinning the
vocabulary changes nothing while nothing calls the command: a stricter check that never runs refuses
nothing, and the ticket would close on a change with no observable effect.

So: wire `authz:observations --unclassified` into `bin/quality` (or state in the runbook that it is
manual, and name who runs it and when) **before** tightening what it accepts. Then the tightening has
somewhere to bite.

## Why the permissiveness is worse than an ordinary unenforced convention

**This is the flip's condition-3 mechanism.** Its purpose is to hold `AUTHZ_ENFORCE` closed until a
human has read every observed denial class and said what it is. **As written it holds the flip closed
until every class carries SOME string.** A gate whose pass condition is "a field is non-empty" does
not verify the review; it verifies that the file was edited.

**`regression` passing is the sharpest form of it.** The `_readme` says a `regression` class "must be
fixed before AUTHZ_ENFORCE flips". The gate lets it through. The one value that names a blocker does
not block.

### `regression` must refuse — DECIDED

**The gate refuses `regression`.** This is not a decision to be taken later, and it is not a change
to what the gate gates: **the `_readme` already states the rule**, and a gate that passes a class its
own documentation calls a blocker simply disagrees with itself. Making it refuse closes that gap and
nothing more.

The burden sits the other way round. **If `regression` should be advisory rather than blocking —
recorded for visibility, not stopping the flip — that is the change that needs a decision**, because
it contradicts the documented rule and would have to amend the `_readme` in the same breath. Default
to the documented behaviour; make the exception argue for itself.

## The closed set — read from the artifact, plus the state it lacks

The `_readme` defines **two** values. The set should be **three**:

| value | meaning |
| --- | --- |
| `expected` | The denial is correct — the caller genuinely lacks the ability, and enforcing it is the intent. |
| `regression` | Enforcing would break legitimate access. Must be fixed before the flip; the entry names the fix. |
| `obsolete` | **New.** The check itself is being removed or re-pointed, so on the flip this class produces no denial at all. The entry names the ticket or commit that retires it. |

**Why the third state is needed.** A class whose check is REMOVED is neither of the first two. On the
flip it yields no denial, so it is not `expected`; and nothing legitimate breaks, so it is not
`regression`. Recording it as `expected` **asserts something false** — that the denial is correct and
will occur — in the one file whose entire purpose is to be the durable review evidence after the
observations table is pruned and dropped. Leaving it `UNCLASSIFIED` **holds the gate shut for a class
nobody will ever hit**, which is an unfalsifiable blocker: no future window can clear it, because the
traffic that produced it can no longer produce a denial.

It also covers the REPLACE case without a fourth state: entries are keyed
`ability|controller_action` (`:151`), so re-pointing a check at a different ability retires the old
key exactly as removal does, and any new key is classified on its own merits.

## The fix — two steps, and the second is ONE change

**Step 1 is the wiring (above).** **Step 2 pins the vocabulary to the closed set AND adds the third
state together**, in a single change — the two halves do not work apart.

Adding `obsolete` alone buys nothing: an unrecognised value already passes, so the state would be
documentation rather than mechanism, and the gate would be exactly as permissive the day after as the
day before. Pinning alone is not enough either — it would make `obsolete` invalid and force a false
`expected` on every removed check, which is the failure this ticket is partly about.

1. Validate every entry's `classification` against the three, and **fail on an unrecognised value**
   with the offending key named — a typo must red, not pass.
2. Fail on a malformed entry: missing `ability`, missing `controller_action`, duplicate key.
3. **Refuse on `regression`** as well as on `UNCLASSIFIED`, per the ruling above — the `_readme`
   already says such a class blocks the flip. `--unclassified` then becomes the wrong name for what
   the check does; rename it (`--unresolved`, or a `--gate` flag) in the same change, so the flag
   does not describe a narrower job than it performs.

## Arms

- **Known positive:** a file whose entries are all valid passes. Written first, because a gate that
  refuses everything is indistinguishable from a strict gate until somebody disables it — the
  `bin/db-exclusive` lesson.
- **Known negatives, one per rejection:** an unrecognised value fails; a missing `controller_action`
  fails; a duplicate key fails; a `regression` entry fails; an absent entry still fails as
  `UNCLASSIFIED` fails today. The `regression` arm is the one that pins the ruling above, so the
  documented rule cannot drift back out of the code.
- **The wiring itself gets an arm**, or it is the same defect one level up: assert that the runner
  invokes the command, the way the other `bin/quality` steps are covered. A gate nothing calls is
  what this ticket is half about, and adding a second uncalled gate to fix the first would be
  self-parody.
- Assert the **error names the offending key**. A gate that fails without saying which entry is wrong
  is a gate people learn to regenerate past.
- `tests/Feature/Rbac/AuthzObservationsCommandTest.php` already covers the `UNCLASSIFIED` path
  (`:46`, an instrument bite-proof) and a passing `expected` entry (`:65`). The new arms extend that
  file rather than starting another.

## Relationship to the other census tickets

Split out of [`observe-mode-has-no-liveness-signal.md`](observe-mode-has-no-liveness-signal.md),
which carries a one-line pointer here. **Different defect, different reader:** that one is about
whether the observation data can be trusted at all; this one is about whether the review of that data
is verified. They fail independently — a live sink with an unverified review is as unsafe to flip on
as a dead sink with a careful one.

**Retention applies here too.** The 149 census rows span 2026-07-21 to 2026-07-31, the newest is 33
days old, and `authz:prune --older-than=30` would delete every one of them while August produced
none to replace them. Classification must precede any prune — and this ticket is about that
classification meaning something when it is written.
