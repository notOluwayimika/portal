# The boundary-lint baseline keys on line TEXT, so a copy-pasted violation is admitted silently

Recorded on `feat/u6-cohort-enrollment-port` (U6 commit 1) while extending `finance-escape-hatches`
to cover `app/Academics/`. The weakness is in `bin/ci-boundary-lint.php` itself and predates that
change; the extension only made it load-bearing over a second directory.

## The mechanism

Findings are keyed by `rule \t relativePath \t trim($line)`:

```php
$found[$rule."\t".$rel."\t".trim($line)] = true;
```

That key is a **set membership test on the trimmed source text of the line**. The baseline is the
same key written to disk, and a run fails only on keys not already in it.

So a violation is admitted with no new baseline entry whenever it produces a key that is already
present — which happens when the offending line is **byte-identical after trimming** to a baselined
line in the same file. Line number, surrounding method, count of occurrences: none of them are in the
key. Three `withoutGlobalScope(SchoolScope::class)` calls and three hundred are the same key.

## Why this is the likely case and not a corner one

`app/Academics/BillableEnrollmentAdapter.php` currently has three baselined entries:

```text
finance-escape-hatches	app/Academics/BillableEnrollmentAdapter.php	$query->withoutGlobalScope(SchoolScope::class)
finance-escape-hatches	app/Academics/BillableEnrollmentAdapter.php	$unscoped = fn ($query) => $query->withoutGlobalScope(SchoolScope::class);
finance-escape-hatches	app/Academics/BillableEnrollmentAdapter.php	->withoutGlobalScope(SchoolScope::class)
```

**Copy-paste is how a fourth one gets written.** Someone adds a method beside the existing cohort
reads, copies the nested closure that strips the scope — because that is what the neighbouring code
does and it is the obvious thing to imitate — and the new line reads `$query->withoutGlobalScope(SchoolScope::class)`,
character for character. The lint stays green. Nobody argues for the fourth hatch, which is the one
thing the rule exists to force.

The rule's whole value is that the **next** escape hatch has to be justified. This is the exact path
by which the next one is not.

## Blast radius

Every `$add()` rule in the file shares the key, so every one of them shares the hole:
`school-id-fallback-context`, `finance-table-outside-finance`, `finance-escape-hatches`,
`decimal-money-cast`, `halting-event-arrow-fn`, `force-create-finance-tests`. The rules with a **zero**
baseline (`approval-seam-missing`, `school-context-guard-missing`) are unaffected in practice, since
with nothing baselined there is no key to collide with.

It is the same shape as the hole recorded in the file's own header — *"a token-grep lint cannot see a
method that reaches the same forbidden behaviour under a different name"* — one level along: a
text-keyed baseline cannot see a second occurrence of a line it has already forgiven.

## Fix

Add an **occurrence count** to the key or to the baseline entry, so the baseline forgives *N*
occurrences rather than a string. Sketch:

```text
finance-escape-hatches	app/Academics/BillableEnrollmentAdapter.php	3	<trimmed line>
```

Check compares counts and fails when the count rises, which preserves the ratchet's "may only shrink"
semantics — a removed hatch lowers the count and is reported as progress, exactly as today.

Deliberately **not** keyed on line number: that would fail the lint on every unrelated edit above a
baselined line, which is worse than the hole and would train people to regenerate the baseline
reflexively — the failure mode that makes any ratchet stop meaning anything.

Regenerating the baseline is a whole-file rewrite (`php bin/ci-boundary-lint.php generate`), so the
format change and the regeneration land together; the diff must be inspected to confirm no entry
disappears.

## Acceptance

- **Bite-proved:** duplicate an existing baselined violation line verbatim in the same file, run the
  lint, watch it FAIL. Paste the failure. A green here means the fix did not land.
- Removing one of several identical occurrences lowers the count and is reported as progress.
- No entry lost across the regeneration, confirmed by `diff` against the previous baseline.
