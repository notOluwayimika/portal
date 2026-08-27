# TICKET — an error handler logs the raw axios error object to the browser console

**Status:** open, low. Harmless where it currently sits; written down for the pattern, not the
instance.

## The fact

`resources/js/pages/admin/scholarships-tab.tsx` calls `console.log(error)` on the raw axios error in
three catch blocks (`:88`, `:123`, `:166`), alongside the toast that actually tells the user
something. Observed by the scholarship-kind drive
(`docs/handoff/drives/2026-08-27-scholarship-kind/`), which was watching the console for page errors.

An axios error object carries `error.config` — the full request, including its **body** and its
**headers** — and `error.response`, including the full response body.

## Why this instance is harmless and still worth writing down

What passes through this particular handler is a scholarship name and a `kind`. Nothing personal,
nothing secret. Authentication here is a Sanctum session cookie rather than a bearer token, so no
credential is in the logged headers either.

The reason to record it is that **this handler is the copyable one**. It sits in a small, readable
tab that anyone building the next admin screen will open as the example. The same three lines on a
screen that posts a guardian's phone number, a student's record, or an import row put that content
into the browser console of whoever is signed in — and console output is not covered by any of this
project's privacy discipline, which governs what the *server* logs and what a *report* prints.

The project's rule is `user#<id>`, `school#<id>`, counts and structure. A raw request body in a
console is none of those, and no lint sees it.

## What closes it

Either drop the `console.log` — the toast is the user-facing half and the network tab already has
the request — or log something deliberately narrow: the status and the URL, never the object.

If any diagnostic value is wanted, `console.error` with a message and the status is both more useful
and bounded. `console.log(error)` is the shape that carries everything by accident.

Worth doing before the next admin screen copies it, rather than after.
