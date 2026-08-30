# TICKET — a caller-supplied percent base survives when no line cites a policy

**Status:** open, contained, deliberately not fixed on `feat/bss-per-student-discount`. Found by that
branch's second cold review, scoped to the governance chain the amend changed.

## The fact

`App\Finance\Actions\GenerateInvoice::resolveDiscountBase()` (`:434`) normalises every percentage
line's base by reading it from the cited policy, so a caller cannot smuggle a wider base in on the
wire. Its docblock (`:425-431`) is explicit about that intent, including the unknown-id case at
`:452`, where `?? null` deliberately falls to the default rather than honouring the caller's claim.

The early return does not follow the same rule:

```php
if ($ids === []) {
    return $lines;      // :445-447
}
```

When **no** line in the batch cites a discount policy, the array is returned untouched and any
`percentBase` the caller supplied survives. The same line, in a batch where some *other* line cites
a policy, would be normalised — so line A's treatment depends on line B.

## Why it is low, and why it is still written down

It is contained twice over, and both containments are real:

- **No wire path sets it.** `GenerateInvoiceRequest` (`:440-467`) never passes `percentBase`, so
  today the field can only be set by a direct in-process caller.
- **The database refuses the row anyway.** `finance_invoice_lines_reduction_guard`
  (`2026_07_26_140002_add_discount_policy_to_finance_lines.php:62-101`) rejects a non-charge line
  citing no policy at INSERT. A percentage line with a base and no policy does not reach the table.

So this is not a live money defect and should not be treated as one. What it is: a normalisation
method with two behaviours, where the docblock states one. The next person to add a caller — the
most likely being whatever eventually posts a reduction from a screen — reads the docblock, believes
the base is always resolved from the policy, and is right in every batch except the one where their
line is the only line.

## What closes it

Delete the early return and let `array_map` run over an empty `$bases`. `$bases[...] ?? null` already
gives the correct answer — the default base — for every line, which is exactly what `:452` does for
an unknown id. The query is the only thing worth skipping, so guard the query rather than the map:

```php
$bases = $ids === [] ? collect() : DiscountPolicy::query()->whereIn('id', $ids)->pluck('base', 'id');
```

One behaviour, one docblock, and the fast path preserved.

**Bite-prove it**: build a spec with a caller-supplied `percentBase` of `total` and no policy id,
confirm it comes back `discountable` (or null), and confirm the arm reds against the early-return
form.
