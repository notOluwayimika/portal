# The `/guardians` page and the `/api/guardians` endpoints are gated on different permissions

**Raised by:** the guardian-create drive on `fix/guardian-create-duplicates`, which lost
a run to it while building a seat. Not that branch's to fix.

## The mismatch

| Surface | Gate | Where |
| --- | --- | --- |
| `GET /guardians` (the Inertia page) | `permission:admin_area.access` | `routes/web.php` |
| `/api/guardians*` (everything the page calls) | `permission:academic_setup.manage` | `routes/api.php` |

The two permissions are unrelated. A role holding **one without the other** is not a
hypothetical: the canonical map is simply the reason nobody has hit it yet, and the
per-school authority matrix is an edit away from producing one.

## What it looks like when it bites

A user holding `academic_setup.manage` + the guardian grants but not
`admin_area.access` signs in successfully, navigates to Guardians, and gets a
**full-page 403**. Not an empty list, not a disabled button — the shell does not render.
The observed failure during the drive was worse than that description: the 403 bounced
to `/login`, so it presents as *"my login does not work"* rather than *"I lack a
permission"*, which is the report a school would file.

The reverse holder — `admin_area.access` without `academic_setup.manage` — gets the
page and then a 401/403 on every XHR it makes, i.e. a shell with permanently empty
tables and no explanation.

## Why it is a ticket

Nothing is currently mis-granted, no isolation boundary is crossed, and no data is at
risk. It is a latent trap in the authority matrix rather than a live defect — but it is
the kind that surfaces as a support ticket about broken authentication, and the cost of
diagnosing it from that starting point is high.

## What closing it looks like

1. Decide which permission owns "may use the Guardians module" and gate both surfaces on
   it. `admin_area.access` is the more honest name for a module gate; `academic_setup.manage`
   reads like a setup-screen permission and is doing double duty.
2. Whatever is chosen, the page and its API must move together, and the change must be
   reflected in `tests/fixtures/route-access-map.json` as a reviewed diff.
3. There is a broader version of this worth a sweep: any Inertia page whose gate differs
   from the gate on the endpoints it calls has one of these two failure modes. This is
   the first one found; nothing establishes it is the only one.
