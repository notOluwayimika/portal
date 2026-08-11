# TICKET — a fresh-clone review cannot run the suite: every Inertia arm 500s on a missing Vite manifest

**Status:** open, not implemented. Raised by the cold review of `feat/fee-schedules-screen`
(U1 commit 2), which hit it. Deliberately not fixed on that branch: the remedy belongs in
`.claude/skills/finance-review/SKILL.md` as a setup step, and that file is not a feature branch's
business to edit.

## The failure

Clone the repository, `composer install`, run the suite. Every test that renders an Inertia page
fails with a 500, not an assertion:

```
Illuminate\Foundation\ViteManifestNotFoundException: Vite manifest not found at: …/public/build/manifest.json
```

and — once a manifest exists but predates the branch's new page — its sibling:

```
Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest: resources/js/pages/admin/finance/fee-schedules.tsx.
```

(`vendor/laravel/framework/src/Illuminate/Foundation/Vite.php:960` and `:1042`. The second was
observed on this branch during implementation, before `npm run build` had been run against the new
page; the first is what a clone with no `public/build` at all gets.)

A reviewer who does not know this reads a 500 where an assertion should be and has to work out
whether the branch is broken. The one who raised this fabricated a `manifest.json` by hand to get
past it.

## The cause is a legitimate arrangement, not a bug

Three facts that are each correct and together produce it:

1. `public/build/` is **gitignored** (`.gitignore:4`), so a clone has no manifest. Committing built
   assets would be worse.
2. `bin/quality` builds them — **step 5**, `pnpm run build` (`bin/quality:194-195`) — and step 5 runs
   _before_ step 14's suite. Anyone running the gate never sees this.
3. A **standalone** suite run (`./vendor/bin/pest`, or a filtered subset) skips step 5 entirely. A
   reviewer verifying one arm of a report runs exactly that.

So the failure is invisible to the workflow the gate was designed around and unavoidable in the
workflow a cold review actually uses. It is not specific to this branch: **any** branch adding or
touching an Inertia page reproduces it, and every future fresh-clone review hits it.

## What it costs, concretely

The arms that render a page are the ones a reviewer most wants to run independently — they are the
ones asserting props are populated, which is the class of defect the whole
`OpeningBalanceOperatorScreenTest` precedent exists for (a 200 with an empty select). On
`feat/fee-schedules-screen` the affected arms are the three `GET /finance/fee-schedules` arms in
`tests/Feature/Finance/FeeSchedulesScreenTest.php`; two of them render a page and cannot pass without
a manifest entry for `resources/js/pages/admin/finance/fee-schedules.tsx`.

**Fabricating a manifest is not a safe workaround** and should not become folklore. A hand-written
manifest satisfies the lookup with a file that was never compiled, so step 5's actual guarantee — that
the bundle _builds_ — is skipped while the tests go green. `bin/quality:184-189` records why that
guarantee is separate from the tsc ratchet: a syntax error that leaves the ratchet at baseline while
the bundle refuses to build has shipped here before.

## Requirement

`.claude/skills/finance-review/SKILL.md` gains a **setup step**, before any instruction to run the
suite: build the frontend once in the clone —

```bash
composer install
npm ci && npm run build     # required: public/build is gitignored, and bin/quality's step 5
                            # is the only thing that normally creates the manifest
```

Two properties the wording needs:

- **Name the failure it prevents**, not just the command. A setup step with no stated consequence is
  the first thing skipped when a reviewer is only running one filtered arm.
- **Say that a real build is the point.** The instruction must not read as "make the manifest exist",
  or the fabrication above becomes the documented remedy.

## Alternatives considered and rejected

| Option                                                            | Why not                                                                                                                                                  |
| ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Commit `public/build/`                                            | Build output in version control; merge conflicts on every frontend change.                                                                               |
| Make the tests tolerate a missing manifest (e.g. `withoutVite()`) | Removes the Inertia arms' contact with the real page name — and that contact is what caught a page registered under a path the route did not render.     |
| Have the suite build on demand                                    | A test run that invokes a bundler is slow and non-hermetic; and it would hide the same gap from the gate, which is the one place it is currently closed. |

## Related

- `bin/quality:184-195` — step 5, and its docblock on why the build is not interchangeable with the
  tsc ratchet.
- `docs/handoff/reports/feat-fee-schedules-screen.md` — the branch whose review hit this.
- `docs/handoff/tickets/reviewer-can-see-implementer-scratchpad.md` — sibling: another property of the
  review setup that is not stated where the reviewer reads.
