# TICKET — a permission refusal renders a dead end, on at least two routes

**Status:** open, pre-existing, and now observed twice by two different drives.

## The fact

A user who signs in successfully and reaches a route their role does not hold gets a **bare 403 page
— no application shell, no navigation, no way back**. They are stranded on a dead end and have to
know to edit the URL.

Two instances, both from browser drives rather than from tests:

- **`/dashboard`**, hit by the finance seats. `maker@drive.test` and `school-b@drive.test` sign in
  and are then refused, and the drive that found it recorded that *"it looks exactly like a broken
  login"* — it is the first screen a first-time driver sees. Documented in the `finance-drive`
  skill's Friction section so the next driver does not spend a session on it.
- **`/setup`**, hit by `maker@drive.test` during the scholarship-kind drive
  (`docs/handoff/drives/2026-08-27-scholarship-kind/`). Same shape: group middleware refuses, no
  shell, no route back.

One is a quirk. Two different routes, found independently, is how this application treats a
permission failure.

## Why it is worth more than it looks

Every guarded route in this system is a candidate. The failure lands on a **legitimate user in the
wrong place** — not an attacker — and it gives them nothing to act on: no statement of what they
lack, no link to somewhere they can reach, no indication they are still signed in.

The population that hits it is exactly the one that cannot self-diagnose. Accounts staff reaching a
setup screen, a form teacher reaching a finance page, a registrar reaching anything at all
(`registrar-reaches-no-role-gated-route.md` says that seat reaches no role-gated route in the
system — so a registrar meets this page as their normal experience).

## A stale claim found while writing this

The skill's Friction entry for `/dashboard` says it was *"filed as a ticket by the drive that
observed it."* **No such ticket exists** — `ls docs/handoff/tickets/` has nothing for it. Either it
was never written or it was written under a name nobody can find.

This ticket is that ticket, covering both instances. When it is picked up, fix the skill's sentence
to point here.

## What closes it

A 403 that renders **inside the shell**: the user stays signed in, keeps their navigation, and is
told plainly that this page needs a permission they do not have. Whether it also names the
permission is a judgement — naming it helps an admin and tells an unauthorised user what to ask for,
and this system's population is small and internal, so naming it is probably right.

Not urgent, and not a security matter — the refusal itself is correct and working, on both routes.
It is the presentation that strands people.
