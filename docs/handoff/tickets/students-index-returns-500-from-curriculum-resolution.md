# `/students` returns 500 — a null dereference in curriculum resolution

**Status:** open · **Opened:** 2026-09-05 · **Found by:** the drive for
`refactor/hand-written-components-leave-the-vendor-directory`, and re-observed on the drive for
`fix/hook-findings-behind-the-vendor-ignore` · **Not fixed here:** it is PHP, unrelated to either
change, and unscoped.

## What was observed

Driving `/students` on the drive fixture as `admin@drive.test`, the page renders its table — **19
rows** — and the browser console carries:

```
[students error] Failed to load resource: the server responded with a status of 500 (Internal Server Error)
[students PAGEERROR] AxiosError: Request failed with status code 500
```

The failing request is one of the page's XHRs, not the document: the Inertia page itself returns 200
and the table paints, which is why this is invisible unless the console is read. **The
opening-balance drive found its second defect in the console rather than on the screen; this is the
same shape.**

## How it was traced

`storage/logs/laravel.log`, on the `drive` channel, at the moment of the request:

```
[2026-09-05 01:37:36] drive.ERROR: Attempt to read property "name" on null
{"file":"…/app/Models/Curriculum.php","line":176}
```

## Why it is not either commit's doing

Both changes are frontend-only. The component move was six `git mv`s and 51 import-path edits; the
hook commit touches five `.tsx` files and one seeder. **Neither diff contains a single line of PHP
under `app/`** — `git status --porcelain | grep -c '\.php$'` returned **0** for the move — so a
server-side null dereference cannot originate in either.

## SECOND OBSERVATION — a different 500, on the post-login navigation, UNATTRIBUTED

Recorded here rather than in a ticket of its own, because it is not attributed and a ticket for an
unattributed symptom invites somebody to go looking for the wrong thing.

Seen on 2026-09-05 during the teachers-screen drive for
`fix/hook-findings-behind-the-vendor-ignore`, as `admin@drive.test`:

```
[error] Failed to load resource: the server responded with a status of 500 (Internal Server Error)
```

**IT IS NOT THE `/students` ONE ABOVE.** That drive never opened `/students`. The server log for the
run shows the error window falling on the post-login hop — `/login` then `/dashboard` at
`03:33:18` — and not on any request this screen makes.

**AND IT IS NOT THE SCREEN UNDER TEST.** Every `/api/teachers` request in that run returned data,
and that is established by BEHAVIOUR rather than by reading status codes: the table rendered 25 rows
on page 1 and 6 on page 2 (31 seeded), the search narrowed to 1 row and `Clear` restored it to 25,
and the spinner appeared on all four triggers plus the status-change refetch. A failing feed cannot
produce those numbers.

**NOR IS IT THIS DIFF'S.** The commit that observed it changes five `.tsx` files and two seeders;
`Curriculum.php:176` is the only PHP error either drive has produced and it belongs to the first
observation.

### The part that is a finding in its own right: IT WROTE NO APP-LOG ENTRY

`storage/logs/laravel.log` gained NOTHING during that run. Its newest entry at the time was the
`Curriculum.php:176` error from an earlier drive an hour before, and no line was appended while the
500 was being served.

**A 500 that logs nothing is a gap in the LOGGING as much as a bug in the request**, and it is
precisely why this one cannot be attributed — by me or by anyone else who meets it. The browser
console is currently the only witness, and a console is not kept: it exists for as long as the tab
does. Whatever the request turns out to be, a 500 reaching a client while the application log stays
silent is its own defect, and closing it is what makes the next occurrence diagnosable rather than
re-derived from scratch.

Worth establishing when somebody picks this up: which handler is answering (the exception may be
escaping before the logging middleware, or being swallowed by a handler that renders a response
without reporting), and whether the same silence applies to every 500 on that path or only this one.

## What a fix would need to establish

- **Which relation is null.** `Curriculum.php:176` reads `->name` on something the fixture leaves
  unset; the fixture is the cheapest reproduction and it reproduces every run.
- **Whether the null is legitimate.** If a curriculum may genuinely have no related record, the read
  needs a guard and the screen needs a fallback label. If it may not, the defect is upstream in
  whatever created the row, and guarding here would hide it.
- **What the screen should show meanwhile.** It currently renders a full table while one of its
  requests fails — the state-confusion class §26 records: the page looks healthy and one of its
  panels is silently empty. Whatever the resolution, that combination should not survive it.
