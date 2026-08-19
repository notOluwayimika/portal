# An ambiguous phone refuses even when the submitted email refutes every candidate

**Raised by:** the fourth and final cold review of `fix/guardian-create-duplicates`,
as a **fix-level** finding. **Deliberately not fixed** — the branch is closed to further
rounds by instruction and the residual risk is being recorded instead.

**Severity: fix.** It refuses. No wrong write, no isolation breach, no audit gap. It is a
usability regression on a path this branch created, and it is of the exact class the
branch exists to remove.

## Mechanism

`app/Services/GuardianMatcher::findInSchool`:

```php
if ($byEmail && $byPhone->contains(fn (Guardian $g) => $g->id === $byEmail->id)) {
    return $byEmail;                     // the email NAMES a candidate → disambiguates
}

if ($byPhone->count() > 1) {
    throw new AmbiguousPhoneMatchException(...);
}
```

The disambiguation branch requires `$byEmail` to be a guardian **already in this
school**. A brand-new address makes `$byEmail` null, so control falls straight through to
the throw. `emailRefutesMatch` — the method built precisely to let an email overrule a
phone match — is **never consulted here**: `GuardianService` applies it only *after*
`findInSchool` returns, and on the throw path it returns nothing.

## The asymmetry, from the branch's own arms

| Candidates on the number | Submission | Outcome |
| --- | --- | --- |
| 1 | new person, own new email | **created**, `reused_existing_guardian: false` |
| 2+ | new person, own new email | **422**, no way to proceed |

So a distinct email is treated as decisive evidence of a second person at n=1 and ignored
at n≥2. There is no principled reason for the flip: if one differing address proves "not
that person", two differing addresses prove "not either of them" at least as strongly.

## Why it matters more than it looks

The branch's own sizing found **14 `(school, phone)` groups on the production copy
already holding more than one guardian row**. So this is the *third* member of a
household that already has two — a household landline with three parents/guardians on
it — not a contrived case.

And the refusal message tells the operator to *"use a number that identifies this
person"*. For a genuinely shared household line, the only way through is to type a phone
number that is not theirs. **That is a hard block with no honest way forward — the same
shape as the `Rule::unique('users','email')` that this branch removed, and which the
report identifies as the direct cause of the reported duplicates.** A dead end is what
made a school invent the workaround that produced three rows for one mother.

## No arm covers it

The three ambiguity arms cover n>1 with no email, n=1, and n>1 with an email naming a
candidate. **None covers n>1 with an email naming nobody**, which is this case.

## What closing it looks like

1. Consult the candidate set with `emailRefutesMatch` **before** throwing: if a submitted
   email refutes *every* candidate, the evidence singles out "none of them" as clearly as
   it singles out one, and the safe direction is to create a new guardian.
2. Add the missing arm — two existing guardians on one number, a third submission with a
   brand-new address → created, `reused_existing_guardian: false` — and a watched red.
3. If refusing is the deliberate choice after all, the message must give a real next
   step, because "use a different number" is not one.
