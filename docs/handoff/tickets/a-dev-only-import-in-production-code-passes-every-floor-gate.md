# A dev-only import in production code passes every floor gate

**Found:** 2026-08-26, on `feat/ccm-fold-surface`, while root-causing a silent `pest --group=arch`
exit 255. Captured here rather than fixed there: this is general guardrail hardening that the CCM
branch merely EXPOSED, and folding a lint change into a feature PR muddies both reviews.

## What happens

`composer.json` maps `Tests\` under **`autoload-dev`** only. The production map is `App\`,
`Database\Factories\`, `Database\Seeders\` — so `database/seeders/` and `app/` ship to production
while `tests/` does not.

Nothing in the enforcement floor refuses a `use Tests\...;` from those production paths. Measured
with `use Tests\Feature\CcmFoldDriveFixtureTest;` restored in `database/seeders/CcmFoldDriveSeeder.php`:

| Gate | Result with the dev-only import present |
| --- | --- |
| `bin/ci-boundary-lint.php` | exit 0 |
| `bin/ci-authz-lint.php` | exit 0 |
| `composer analyse` (Larastan) | 0 errors |
| `./vendor/bin/pint --test` | passes — **Pint is what WROTE it** (see below) |
| `pest --group=arch` | **exit 255, zero bytes on every stream** |

## Why the arch "catch" is not a control

The arch pass is the only thing that reacts, and it reacts by dying with no output at all — the
fatal is trapped in an output buffer Pest never flushes, so stdout, stderr, `--log-junit`, the PHP
error log and `laravel.log` are all empty. It cost four bisection rounds and two wrong conclusions
to read a message that a lint could have printed in one line.

**A control that enforces a boundary by dying silently is indistinguishable from one that does not
enforce it, and is arguably worse** — it manufactures the appearance of coverage while making the
next occurrence more expensive, not less. That is the unenforced-control pattern this project keeps
paying for, sitting inside the guardrails themselves.

## How it gets written in the first place — nobody types it

This is not a mistake a reviewer would expect to see, because a human did not write it. Pint's
`fully_qualified_strict_types` fixer rewrites a **docblock** reference:

```php
 * {@see \Tests\Feature\CcmFoldDriveFixtureTest} asserts the guard fires on what it builds
```

into a real import plus a shortened docblock:

```php
use Tests\Feature\CcmFoldDriveFixtureTest;   // ← added by the formatter
 * {@see CcmFoldDriveFixtureTest} asserts the guard fires on what it builds
```

Pint's output names only the fixer (`fully_qualified_strict_types`), not the dependency it created.
So: you write a comment, the formatter promotes it to a dependency, and every gate passes. A
docblock citing a test from production code is a REASONABLE thing to write — which is why this needs
a lint rather than a convention.

## Severity, stated precisely

An **unused** `use` is never resolved at runtime, so this is not an unconditional deploy-time fatal —
claiming that would be wider than the artifact. What is true:

- under `composer install --no-dev` the name is **unresolvable**, so anything that resolves it
  fatals: static analysis, arch/reflection passes, and any runtime path that touches the symbol;
- the same fixer promotes docblock FQCNs used in **type positions** too, and those ARE resolved by
  ordinary reflection — the container reflects constructor signatures on every resolve;
- it is silent in dev precisely because the dev autoloader has `Tests\`, so the bomb is only armed
  where nobody is looking.

Latent rather than live, and one type-position promotion away from live.

## THIS IS ALREADY LIVE ON `staging` — it is not hypothetical

Found while checking my own files were clean:

```
app/Enums/Permission.php:5:  use Tests\Feature\Rbac\ForcingMigrationsDoNotStripLaterGrantsTest;
app/Enums/Permission.php:37: *  DEPLOY rather than at build. {@see ForcingMigrationsDoNotStripLaterGrantsTest}
```

Same signature exactly — a `{@see ...}` docblock at line 37 and Pint's promoted import at line 5 —
in `app/Enums/Permission.php`, which is production-autoloaded (`App\`), shipped, and loaded on
essentially every request. Introduced by `81c08bed`, present on `staging`, and it has passed every
gate ever since.

It does not fatal the arch pass the way the CCM fixture did, because that test file declares no
top-level functions, so a second include redeclares nothing. **That difference is luck, not
safety** — it is the same boundary breach, and it is the reason this ticket is worth a lint rather
than a one-line cleanup: the symptom is loud only when it happens to collide, and silent otherwise.

Left in place deliberately: it is pre-existing, on `staging`, and unrelated to
`feat/ccm-fold-surface` — fixing it there would widen a feature PR into someone else's file. The
lint should land with the cleanup of every existing instance, this one included.

## MEASURED: the population is 1, and the trigger is exact

Run before proposing the rule, deriving both sets from `composer.json` rather than assuming them —
production is `App\` → `app/`, `Database\Factories\` → `database/factories/`,
`Database\Seeders\` → `database/seeders/`; the only dev-only root is `Tests\`.

**Actual violations — imports of a dev-only namespace from a production path: 1.**

```
app/Enums/Permission.php:5   use Tests\Feature\Rbac\ForcingMigrationsDoNotStripLaterGrantsTest;
```

So the lint PR is small: fix one file, then add the rule. It can go green on its own first run.

**The trigger is `/** */` vs `/* */`, and this was measured, not assumed.** Pint's
`fully_qualified_strict_types` rewrites a fully-qualified name in a real PHPDoc block into a short
name plus an import — reproduced on a scratch copy:

```php
/** … {@see \Tests\Feature\CcmFoldDriveFixtureTest} … */   // before
use Tests\Feature\CcmFoldDriveFixtureTest;                  // after: import ADDED
/** … {@see CcmFoldDriveFixtureTest} … */                    //        docblock shortened
```

A plain block comment is left alone. `database/seeders/RbacSeeder.php:136` carries the same
`{@see \Tests\Feature\Rbac\ForcingMigrationsDoNotStripLaterGrantsTest}` and has NOT been
promoted — because that comment opens `/*`, not `/**` (verified by running Pint on a copy: no
change). So it is not an armed pipeline case; it becomes one only if someone upgrades that comment
to a docblock, which is an ordinary tidy-up nobody would think twice about.

That is the useful precision: **the difference between a violation and a non-violation here is one
asterisk**, invisible at review, and decided by a formatter rather than an author.

## Proposed fix — scan, fix all, then gate, as ONE unit

Order matters: a rule that lands red against `app/Enums/Permission.php` cannot go green on its own
first run and hands the next person a cleanup. The scan above IS the lint's first run, done early;
the population is 1, so the PR is: fix that one import, then add the rule.

A small, LOUD floor lint — `bin/ci-dev-namespace-lint.php`, wired into `bin/quality` beside the
other lints. Refuse any import of a namespace declared under `autoload-dev` from a path declared
under `autoload`, and fail naming the file, the line and the offending symbol.

Derive both sets **from `composer.json` itself**, not from a hardcoded `Tests\`: a second dev-only
namespace added later must be covered without anyone remembering this ticket. That is the same
"compare the set, not a remembered instance" discipline as the tripwire-sibling rule.

Arms it needs: a production file importing a dev-only namespace **reds**; the same import inside
`tests/` **passes** (it is legitimate there); and a production file importing an ordinary production
namespace **passes**, so the lint cannot pass by refusing everything.

## Related

- `docs/handoff/reports/feat-ccm-fold-surface-drive.md` § "RESOLVED — `pest --group=arch` exited 255"
  — the full root-cause trace and the buffer-drain instrument that read the fatal.
