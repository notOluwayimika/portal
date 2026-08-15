# TICKET — there is no JavaScript test runner, so no frontend logic in this repository is tested

**Status:** open, not implemented. Raised by `feat/u8-invoice-modal-discount-policy` (U8 commit 4),
which added branching client logic to `resources/js/components/finance/new-invoice-modal.tsx` and had
nothing to assert it with. Deliberately NOT fixed on that branch: installing a runner is a
toolchain-and-gate decision (which runner, whether it becomes a `bin/quality` step, whether it is
ratcheted like `tsc`), not something a feature commit gets to settle on the way past.

## The fact

`resources/js/` is **62,912 lines of hand-written TypeScript and TSX with zero automated tests**
(99,220 lines including the wayfinder-generated `resources/js/actions/` and `resources/js/routes/`),
because there is no runner that could run one. Derived, not remembered:

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

`errorLinesFrom` in `resources/js/components/finance/new-invoice-modal.tsx:55-98` is real branching
logic, written to a specification, with edge cases its own docblock enumerates at length:

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
(`bin/lint-changed.sh:63-77`).

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
     the count does not rise. This is `bin/quality:186-193`'s own stated reason for step 5 existing.
  3. **It is a count, not a set.** Removing one pre-existing error while adding one new one nets to
     zero and passes. `docs/` already records the ratchet as a known false-green.

### Step 5 — `frontend build (vite …)` (`bin/quality:194`)

`pnpm run build`. Not ratcheted and not changed-files-scoped.

- **Catches:** the bundle failing to compile — a syntax error, an unresolvable import, a broken JSX
  block. The comment at `bin/quality:186-193` records the merge artifact that got past all eleven
  gates then in place and was found by a human running the build by hand.
- **Cannot catch:** anything a compiling bundle can do wrong, which is everything this ticket is
  about. A bundle that builds perfectly and posts the wrong payload is a green.

### Step 9 — `money lint (UI: money via formatNaira, no JS money math)` (`bin/quality:215`)

`bin/ci-money-lint.php`. Line-regex over `resources/js`, with a total ban inside
`resources/js/pages/admin/finance/` and `resources/js/components/finance/`
(`bin/ci-money-lint.php:41-44`).

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

## Not proposed here

Which runner, whether it becomes step 16, whether it is ratcheted, and what the first tests would be
are all open, deliberately. The one thing this ticket does claim is that the current answer — none —
is a position nobody chose; it is where the repository happens to be.
