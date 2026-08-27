# TICKET — the catalog's single-writer invariant greps one literal in one directory

**Status:** open, not fixed. Found while reviewing `feat/discount-policy-base-control`, by reading
the arm that a review block had cited as the reason the BSS policies must go through governance.

## What the arm asserts

`tests/Feature/Finance/DiscountPolicyTest.php:305-314`:

```php
$writers = collect(Finder::create()->files()->in(app_path())->name('*.php'))
    ->filter(fn ($f) => str_contains($f->getContents(), 'DiscountPolicy::create('))
    ->map(fn ($f) => $f->getFilename())
    ->values()->all();

expect($writers)->toBe(['ApproveDiscountPolicyChange.php']);
```

The invariant it stands for is real and load-bearing: the discount catalog changes in exactly one
auditable place, so "the ED approved this set of terms" is a fact about every row rather than a
convention. Everything downstream — the base axis, the checker's view, the inheritance rule — rests
on it.

## The two holes

**It searches `app_path()` only.** Nothing under `database/` is scanned. A seeder, a factory or a
data migration that creates policies is invisible to it.

**It matches the literal `DiscountPolicy::create(`.** A raw
`DB::table('finance_discount_policies')->insert(...)`, a `DiscountPolicy::insert()`, an
`updateOrCreate`, or a `firstOrCreate` all write the catalog and none of them contain that string.

So the arm proves that no file under `app/` calls one particular Eloquent method. It does not prove
what its own name claims, and what the rest of the design assumes.

## Why it stops being theoretical next week

The BSS scholarship scheme needs eight policies — one per distinct `(percentage, base)` pair, four
percentages across two bases. Eight rows is exactly the size where a seeder is the obvious tool, and
a seeder is precisely the shape this arm cannot see. The result would be eight policies in the
catalog that no maker submitted and no checker approved, with the guard that exists to prevent that
still green.

**Until this is closed, the constraint has to be carried by a human: the BSS policies are authored
through the form or the endpoint, like any other.** That belongs in the import commit's brief, not
in a reviewer's memory.

## What closes it

Two independent widenings, and the first is the cheaper and the more important:

1. Scan `base_path()` rather than `app_path()`, excluding `vendor/`, `tests/` and `node_modules/`.
   That alone brings `database/` into view.
2. Match on the table as well as the model — add `finance_discount_policies` as a second needle so a
   raw query builder write is caught, and widen the method set beyond `create(`.

Both are string matching over source, which the repo already accepts in three other places
(`ApprovalsQueueFeedCoverageTest`, `NotificationDeepLinkRouteTest`,
`PestNegatedExpectationMessagesTest`) for the same reason each of them gives: the alternative is
nothing.

**Bite-prove it before trusting the widened form.** Plant a `DB::table('finance_discount_policies')
->insert(...)` in a scratch seeder, confirm the arm reds, remove it. An arch arm that has never been
made to fail is a claim, not a guard — and this one has been green while blind for its whole life,
which is what a non-discriminating test looks like from the outside.
