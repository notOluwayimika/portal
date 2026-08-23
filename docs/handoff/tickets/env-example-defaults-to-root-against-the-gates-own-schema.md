# `.env.example` defaults the database connection to root, against the gate's own schema

**Status:** open, not implemented. Raised by the cold review of `feat/server-side-money-formatter`,
2026-08-23. Filed as a file, not fixed there: the branch had one idea and this is not it.

## What is there

`.env.example:23-28` ships:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=brookstone_portal_db
DB_USERNAME=root
DB_PASSWORD=…
```

(Values above are read from `.env.example`, the tracked template — not from anyone's `.env`.)

## Why it matters

`composer setup` copies this file to `.env` when one does not exist, so **the first thing a fresh
clone does is establish a root connection**, and the developer has to notice and undo it rather than
opt into it. A template is a default, and a default that hands out the superuser is one nobody
chose — it is simply what was in the box.

Two specifics make it sharper here than the generic "don't develop as root":

1. **It points at a real schema, not a placeholder.** `brookstone_portal_db` is a database name that
   exists on developer machines. A template naming a live-looking schema invites a first run that
   migrates something the developer did not mean to touch — and this project's own gate learned that
   lesson the expensive way, which is why `bin/quality-clean-db` builds a throwaway database instead
   of trusting the ambient one.
2. **The test suite is a second schema on the same connection.** `DB_DATABASE=portal_testing
   ./vendor/bin/pest` overrides the name but nothing else, so the suite runs as root too. A test that
   is wrong about `DROP`/`CREATE` has superuser reach over every schema on that server, including the
   local production copy this project keeps for census and drive work.

## Not asserted here

Whether the tracked password value is real or a placeholder — that is a question for whoever owns
the template, and reading anyone's actual `.env` is out of bounds. The finding stands either way:
the USERNAME is the part that grants the authority.

## Shape of a fix (not chosen)

A least-privilege application user, with the template naming it and a placeholder password; the
schema name a placeholder rather than a plausible real one; and — if the setup path should keep
working out of the box — a documented one-liner that creates that user and grants it only what the
app and the suite need. That last part is what makes this more than a one-line edit, which is why it
is a ticket.
