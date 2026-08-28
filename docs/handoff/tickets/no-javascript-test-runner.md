# TICKET — there is no JavaScript test runner, so no frontend logic in this repository is tested

**Status: CLOSED — the runner landed at `66cc22b` (2026-08-23), `test(frontend): the money boundary
was exempt from the lint on review alone`.** Corrected 2026-08-28 by `feat/bss-import-screen`, which
found this ticket still reading as open and still being cited as open by docblocks written after the
runner existed.

`vitest` is installed (`^4.1.11`, devDependency), configured in a dedicated `vitest.config.ts` —
separate from `vite.config.ts` on purpose, so that running the tests does not drag in the wayfinder
plugin and regenerate `resources/js/{routes,actions}` underneath the gate that is measuring them —
exposed as `pnpm run test:js`, and wired into `bin/quality` as its **last** step. Four test files
exist: `resources/js/lib/format.test.ts`, `resources/js/lib/rollover-batch-status.test.ts`,
`resources/js/components/finance/money-input.test.ts` and
`resources/js/pages/admin/finance/discount-policies.test.ts`.

Re-derive rather than carrying these numbers, which is the whole reason the section below insists on
it: `grep -c '^\s*step "' bin/quality` for the step count (18 at `4b2ae85`), and
`find resources/js -name '*.test.ts' -o -name '*.test.tsx'` for the files. Note that `bin/quality`'s
own prose header still calls the vitest step "17" while the `step()` counter makes it 18 — the header
was written when the script had one fewer step and nothing checks prose against the counter.

**What is NOT closed by this, and is deliberately not re-opened as this ticket.** The runner exists;
coverage does not. Every property this ticket names as unguarded is still unguarded — `errorLinesFrom`,
`selectablePolicies`, `patchForKind`, and the invoice-kind reset named at the end — because four test
files over 63k lines of TS/TSX is a runner with a foothold, not a tested frontend. The environment is
`node`, so no test here renders a component yet; the config says what to do at the moment one needs to
(`// @vitest-environment jsdom`, per-file). A new ticket for coverage would be honest; leaving THIS one
open is not, because it sends the next reader to install a runner that is already installed and already
running on every push.

**Everything below this line is the original ticket, preserved.** Its analysis of what each gate step
can and cannot catch remains accurate for the fifteen PHP-side steps and for the four that read
`resources/js` without executing it; only its central claim — that nothing executes application
JavaScript — has been overtaken. Where it says `bin/quality` is 15 steps, that was true when written.

---

**Status when raised:** open, not implemented. Raised by `feat/u8-invoice-modal-discount-policy`
(U8 commit 4), which added branching client logic to
`resources/js/components/finance/new-invoice-modal.tsx` and had nothing to assert it with.
Deliberately NOT fixed on that branch: installing a runner is a toolchain-and-gate decision (which
runner, whether it becomes a `bin/quality` step, whether it is ratcheted like `tsc`), not something a
feature commit gets to settle on the way past.

## The fact

`resources/js/` is **63,177 lines of hand-written TypeScript and TSX with zero automated tests** at
`7894086`, the commit that ships this ticket. A clean checkout carries no `resources/js/actions/` or
`resources/js/routes/` — both are wayfinder-generated and gitignored — so hand-written and total
coincide on any named sha; a working tree where wayfinder has run adds roughly another 36,000 lines
of generated client, which are not source and are excluded here. (An earlier draft said 62,912,
which is the figure at the branch point `9fa55a7`, and quoted a 99,220 total that belonged to a
working tree rather than to a commit. Re-derive with the sha you care about:
`git worktree add --detach <dir> <sha> && cd <dir> && find resources/js -name '*.ts' -o -name '*.tsx' | xargs wc -l | tail -1`.)

There is no runner that could run a test. Derived, not remembered:

```
$ node -e "const p=require('./package.json');console.log(Object.keys({...p.devDependencies,...p.dependencies}).filter(d=>/(^|[^a-z])(vitest|jest|mocha|karma|ava|tape|cypress|playwright|puppeteer|happy-dom|jsdom)([^a-z]|$)|testing-library/i.test(d)))"
[]

$ ls node_modules/.bin | grep -Ei 'vitest|jest|mocha|cypress|playwright|karma|ava|tape'
(no output)

$ ls | grep -Ei 'vitest|jest|cypress|playwright|karma'
(no output)

$ find resources/js -type f \( -name '*.test.*' -o -name '*.spec.*' \)
(no output)

$ node -e "console.log(Object.keys(require('./package.json').scripts))"
[ 'build', 'build:ssr', 'dev', 'format', 'format:check', 'lint', 'lint:check', 'types:check' ]
```

No runner in `package.json`, no runner binary installed, no runner config file, not one test file, and
no `test` script for one to hang off. `phpunit.xml` + Pest cover PHP only; nothing in `bin/quality`
executes a line of application JavaScript.

The drive procedure (`.claude/skills/finance-drive/SKILL.md`) is the only thing that has ever executed
this code with intent, and a drive is a **manual, one-off observation** — it is a person watching a
browser once, not a regression check that runs again next month.

## The example this ticket is named after

`errorLinesFrom` in `resources/js/components/finance/new-invoice-modal.tsx:153-196` (docblock from
`:131`) is real branching logic, written to a specification, with edge cases its own docblock
enumerates at length:

- an `errors` object present vs. absent;
- **every** message rather than `Object.values(errors)[0]?.[0]`, explicitly departing from the
  established shape in `edit-pivot-modal` and `student-guardians-panel`, because the pre-check names
  every offending line in one response;
- a field key of exactly three dot-segments beginning `lines` → the `Line N — ` prefix, with `N`
  computed as `index + 1`;
- a key of any other shape, or a non-`lines` key, → the bare message, so nothing renders `Line NaN`;
- `messages` as an array vs. a bare scalar;
- non-string and empty-string messages dropped;
- an `errors` object that yields zero usable messages falling through to the `message` fallback;
- the fallback itself, which the docblock calls "load-bearing rather than defensive" because
  `GenerateInvoice` still answers a plain `{"message": …}` for every `BusinessRuleException`.

That is at least eight behaviours. **Zero of them are asserted anywhere.** Its correctness was
established by one-off manual measurement at the time it was written and has had no guard since.
U8 commit 4 adds `selectablePolicies` and `patchForKind` in the same file, on the same terms.

## What `bin/quality` has for frontend code, and what each part can and cannot catch

`bin/quality` is **15** steps (`grep -c '^\s*step "' bin/quality`; the `[%d/15]` literal is at
`bin/quality:59`). Four of them read `resources/js`:

### Step 3 — `lint changed files (Pint / Prettier / ESLint, check mode)` (`bin/quality:176`)

Runs `bin/lint-changed.sh "$BASE"`, which is diff-aware: Prettier over changed
`resources/*.{ts,tsx,js,jsx,vue,css,json}`, ESLint over changed `*.{ts,tsx,js,jsx}`
(`bin/lint-changed.sh:62-67` and `:69-74`; the changed-file list itself is built at `:51`).

- **Catches:** formatting drift; unused variables; `import/order`; `@typescript-eslint` recommended
  rules; `react-hooks` recommended-latest (missing/incorrect dependency arrays,
  `set-state-in-effect`); `consistent-type-imports`; the `@stylistic` padding rules.
- **Cannot catch:** anything about behaviour. ESLint has no idea whether `index+1` should be
  `index`, whether a filter's predicate is inverted, whether a state field is cleared on the
  transition that requires it, or whether a fetch sends the field the server reads. A function that
  returns the exact opposite of its specification lints clean.

### Step 4 — `types (tsc ratchet vs tsc-baseline)` (`bin/quality:180`)

`pnpm run types:check` piped into `bin/ci-tsc-ratchet.php`, which counts `error TS\d+:` occurrences
and fails only when the count **exceeds** the committed baseline (`tsc-baseline`, currently `42`).

- **Catches:** a genuinely new type error — a field that does not exist on a type, a wrong argument
  type, a missing property on an object literal assigned to a declared type.
- **Cannot catch:** three separate things, and all three matter here.
  1. **Behaviour.** Types constrain shape, never value. `kind === 'charge'` and `kind !== 'charge'`
     typecheck identically.
  2. **A syntax error.** A file that fails to parse produces no `error TS` lines from that file, so
     the count does not rise. This is `bin/quality:184-193`'s own stated reason for step 5 existing.
  3. **It is a count, not a set.** Removing one pre-existing error while adding one new one nets to
     zero and passes. `docs/` already records the ratchet as a known false-green.

### Step 5 — `frontend build (vite …)` (`bin/quality:194`)

`pnpm run build`. Not ratcheted and not changed-files-scoped.

- **Catches:** the bundle failing to compile — a syntax error, an unresolvable import, a broken JSX
  block. The comment at `bin/quality:184-193` records the merge artifact that got past all eleven
  gates then in place and was found by a human running the build by hand.
- **Cannot catch:** anything a compiling bundle can do wrong, which is everything this ticket is
  about. A bundle that builds perfectly and posts the wrong payload is a green.

### Step 9 — `money lint (UI: money via formatNaira, no JS money math)` (`bin/quality:215`)

`bin/ci-money-lint.php`. Line-regex over `resources/js`, with a total ban inside
`resources/js/pages/admin/finance/` and `resources/js/components/finance/`
(`bin/ci-money-lint.php:40-44`, the two path predicates at `:42-43`).

- **Catches:** `Intl.NumberFormat` / `.toLocaleString(` outside `resources/js/lib/format.ts`; a money
  identifier adjacent to an arithmetic operator; any `.reduce(` inside the Finance UI.
- **Cannot catch:** whether the money that goes through the sanctioned helpers is the **right**
  money. `sumMinor` over the wrong array of lines, or a sign flipped before it reaches the helper,
  is invisible — it is a textual rule about which functions are called, not about what they are
  called with.

### The other eleven steps

Do not read `resources/js` at all. Step 15 runs the Pest suite, which exercises the HTTP stack and —
per `docs/finance/drive-environment.md:8-10` — is structurally blind even to server-rendered output,
let alone to client logic that never reaches PHP.

## The net position

For a change confined to `resources/js`, `bin/quality` proves that the code is **formatted, lints
clean, adds no net type error, compiles into a bundle, and calls no banned money function**. It
proves nothing whatsoever about whether the code does what it is supposed to do. A frontend
regression on this platform is caught by a human opening a browser, or it is not caught.

This is worth writing down because the gate is loud and thorough and green, and a reader who has
watched fifteen steps pass has every reason to believe the change was checked. For PHP that belief is
mostly warranted. For TypeScript it is not warranted at all, and nothing in the output says so.

## A concrete property nothing here can red, named because it was reached for and could not be had

Added by `feat/u7-supplementary-invoice-wire` (2026-08-20). The change put an invoice-kind select on
the New invoice modal — `scheduled` (the term bill) or `supplementary` (a one-off charge). The
property is:

> **When the dialog is opened for a second student, the invoice-kind select is back on `scheduled`
> and has not carried over the choice made for the first.**

`resources/js/components/finance/new-invoice-modal.tsx:283-305`,
`:287` (`setInvoiceKind('scheduled')`), `:339-353` (the effect).

**Why this one is worth naming rather than being one more unproven frontend property.** Its failure
is silent and creates the wrong financial document. If the reset does not fire, a bursar who raises a
supplementary charge for student A and then opens the dialog for student B posts
`kind: "supplementary"` for a student who needs the term bill. That request **succeeds** — 201, no
refusal, the ordinary "Invoice created." toast — because a supplementary invoice is by design not
constrained by the one-per-episode unique index and cannot collide with anything. There is no error
state anywhere in the flow, and `InvoiceResource` does not serialise `kind`
(`docs/handoff/tickets/nothing-shows-which-invoices-are-supplementary.md`), so no screen shows what
was created either. The only signal is a database column nobody is looking at.

Compare the failure it would replace: the defect that branch FIXED was a bursar being *refused* with
a clear sentence. This one is strictly worse — a wrong document, created quietly.

**What the gate can say about it: nothing.** Not a subset of the fifteen steps — none of them. Pint,
Prettier and ESLint see formatting and lint rules; `tsc` sees types, and both branches of the bug are
well-typed; the build sees that it compiles; the money lint sees which functions are called; the Pest
suite never reaches `resources/js` at all. The property is about *when a React effect fires relative
to a prop change*, which is exactly the class this ticket exists to record.

**What the branch did instead**, so the substitute is on the record with its limits:

1. **Re-derivation from source.** The effect at `:339-353` depends on `[isOpen, loadEnrollment,
   loadPolicies]`; `loadEnrollment` is a `useCallback` over `[student.uuid]` (`:305`), so changing
   student re-creates it and re-fires the effect. `loadEnrollment` sets `setEnrollment(null)`
   (`:284`) and `setInvoiceKind('scheduled')` (`:287`) synchronously, before its first `await`
   (`:290`). The select renders only inside `{enrollment && (` (`:449`), so it is unmounted for the
   whole window. The property holds by reading. **That is an argument, not a measurement** — the
   same argument would have been just as convincing about a version that was wrong.
2. **A drive that did not cover it.** The drive opened the modal on a second student, but on a
   *fresh page load*, and the report does not record a same-session open following the supplementary
   submit. So the capture settles that the default does not follow `already_invoiced`, and settles
   nothing about carry-over.

**The cheap partial, available today and not done:** the drive script can open the dialog for a
second student in the same browser session immediately after a supplementary submit and read the
trigger's text. That is a real measurement and costs one step. It is not a *test* — it does not run
on push, nobody re-runs it, and it reds for whoever next drives the screen and only then. Recording
it as the substitute rather than the fix.

`tests/Feature/Finance/ReductionPreCheckTest.php:383-389` already states this limitation for the
sibling property (`patchForKind` clearing a stale policy id), pinning the *server's* acceptance of a
payload shape and saying outright that it "does not change when JavaScript changes". Same shape of
gap, one layer over: there the server refuses the bad payload, here it accepts it.

## Not proposed here

Which runner, whether it becomes step 16, whether it is ratcheted, and what the first tests would be
are all open, deliberately. The one thing this ticket does claim is that the current answer — none —
is a position nobody chose; it is where the repository happens to be.
