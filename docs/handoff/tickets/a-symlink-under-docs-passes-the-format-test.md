# A symlink under `docs/` passes the format test

**Status:** open · **Filed:** 2026-08-21, from the targeted review of `feat/docs-only-gate`
**Location:** [bin/is-docs-only-push](../../../bin/is-docs-only-push) — the `DOC_EXTENSIONS` test

## The shape

`bin/is-docs-only-push` tests a path's directory and its extension. It does not look at the
blob's MODE. A symlink is a `120000` blob whose contents are a path, so:

```
$ ln -s ../app/T.php docs/evil.md
$ git add docs && git commit -qm "docs: a note"
$ git ls-tree -r HEAD -- docs
120000 blob b7d7d90…  docs/evil.md

$ bin/is-docs-only-push <base> HEAD
docs/evil.md
exit=0                      # docs-only: the gate does not run
```

`docs/evil.md` is a **pointer at source**, not a document. Repointing it is a one-line change to
a file the rule calls documentation, and the push that repoints it skips the gate.

## Both halves of what is true today

**Nothing follows it.** No code in this repository dereferences a path under `docs/`; the
`NothingReadsDocumentationTest` sweep reports 0 violations, so there is no reader whose
behaviour a repointed symlink would change.

**A reader of it would be caught.** If code did acquire one — `file_get_contents(base_path('docs/evil.md'))`
— that is a `base_path` call site resolving to a `docs/**.md` path, which is exactly what the
sweep flags. The exposure is therefore bounded by the same invariant that bounds the rest of the
rule, with the same limits (a runtime-assembled path, or an idiom the sweep does not name, is
invisible in this case as in every other).

## Three further shapes that pass and are inert

Measured in a planted repository; all four paths below were judged documentation, `exit=0`:

| path | mode | why it passes | why it is inert |
| --- | --- | --- | --- |
| `docs/x.php.md` | `100644` | basename ends `.md` | nothing treats `*.php.md` as PHP — see below |
| `docs/.md` | `100644` | basename `.md` has a dot, extension `md` | a dotfile named `.md`; no reader, no loader |
| `docs/a.php/b.md` | `100644` | a `.php` DIRECTORY component, `.md` leaf | the directory name is not an extension to anything |

**The evidence that nothing treats `*.php.md` as PHP**, all three read rather than assumed:

- `bin/lint-changed.sh:43` matches `*.php)`, which requires the path to END in `.php`.
  `x.php.md` does not.
- `phpstan.neon:11-12` — `paths:` is `- app`. Nothing under `docs/` is analysed at all.
- `composer.json` autoload is PSR-4 `App\ => app/`, `Database\Factories\ => database/factories/`,
  `Database\Seeders\ => database/seeders/`, plus one `files` entry `app/Helpers/Helper.php`;
  autoload-dev is `Tests\ => tests/`. No mapping reaches `docs/`.

## What a fix would have to decide

Whether the checker should read the blob mode (`git diff --raw` carries it, or
`git ls-tree`/`git cat-file` per path) and refuse anything that is not `100644`/`100755` — which
would also refuse gitlinks, already refused today for having no extension — and what it should do
about a symlink that already exists and is merely edited elsewhere in the same push.

Any such change needs an arm in `tests/Feature/Quality/DocsOnlyPushCoverageTest.php` planting a
`120000` blob and asserting the refusal, alongside the four shapes above as the controls.
