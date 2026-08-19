# `already_linked` is returned by both create paths and read by nothing

**Raised by:** the fourth and final cold review of `fix/guardian-create-duplicates`,
as a **fix-level** finding. **Deliberately not fixed** — the lead closed the branch to
further rounds and asked for residual risk to be written down rather than iterated on.
This is that write-up. Treat it as ship-blocking for the *next* touch of these screens,
not as a reason to hold the merge.

**Severity: fix.** The guard refuses rather than corrupts, and the outcome is strictly
safer than the pre-branch behaviour it replaced (a silent portal-login revocation).
Nothing is written wrongly. The operator is merely not told.

## Mechanism

The branch added a rule — *on a reuse path an existing link is reported, never
rewritten* — and both call sites report it:

- `app/Http/Controllers/GuardianController.php` — `store` returns `'already_linked' => $alreadyLinked` (an array of admission numbers)
- `app/Http/Controllers/GuardianController.php` — `attach` returns `'already_linked' => ! $attached` (a boolean) plus an explanatory `message`

```
$ grep -rn "already_linked" resources/js/
(no matches)
```

Nothing on the frontend reads either. `resources/js/components/students/add-guardian-modal.tsx`
posts and immediately calls `onAdded()`; that file is **not in the branch's diff**.

## The failure, concretely

An operator opens a child's profile → Add Guardian → enters a guardian already linked to
that child, having changed the relationship or unticked Primary. The server correctly
declines to rewrite the link and answers **201**. The modal discards the body, closes,
and refreshes the list. The operator is shown success. Nothing they asked for happened,
and they are not told which of the two occurred.

**This is the branch's own defect class — an action reported as done and silently not
applied — reintroduced by the guard that removes it.** That is the third time in this
branch's history that a fix has recreated the shape it was closing, and it is the single
most reliable lesson in the record: a server-side refusal is only half a fix until a
consumer reads it.

Sharper still: `add-standalone-guardian-modal.tsx` **does** read
`reused_existing_guardian` from the very same response and raises a toast, under a
comment explaining that a returned signal nobody reads is a false claim of having
informed the operator — and ignores the `already_linked` array sitting beside it.

## Evidence weakness worth recording with it

The round-4 drive of this fix pasted the POST wire response and concluded *"the response
says plainly that nothing changed and where to go to change it."* Every other drive
subsection in that report pastes an `ERRORS ON SCREEN` or `RENDERED` line; this one
pastes the wire only. The claim was about the response, not about the screen — but it is
one word away from reading as a claim about the screen, and the `Not driven` list does
not carry it. **The drive proved the API and not the UI, and said so only implicitly.**

## What closing it looks like

1. Read `already_linked` in `add-guardian-modal.tsx` (boolean + `message`) and the array
   form in `add-standalone-guardian-modal.tsx`, and surface both — a toast is enough, and
   the `reused_existing_guardian` toast three lines away is the pattern.
2. Drive it, and paste a **rendered** line, not the wire.
3. If it is instead deliberately deferred, list it in the report's *Not done* the way
   `reused_guardians` already is.
