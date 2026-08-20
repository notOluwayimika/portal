# TICKET — row 201 of a bulk-run outcome bucket is unreachable from the screen AND from the API

**Status:** open, not implemented. Raised by the cold review of `feat/u6-bulk-run-screen` (U6 commit
4). The cap is that branch's own decision and the announcement is deliberate; what it does not have is
a way past.

## The fact

`App\Finance\Http\Controllers\BulkInvoiceRunController`:

```php
private const ROWS_PER_BUCKET = 200;
…
->limit(self::ROWS_PER_BUCKET + 1)->get();

$buckets[$outcome->value] = [
    'total'     => (int) ($totals[$outcome->value] ?? 0),
    'truncated' => $rows->count() > self::ROWS_PER_BUCKET,
    'rows'      => $this->serializeRows($rows->take(self::ROWS_PER_BUCKET)->all()),
];
```

`GET /api/v1/finance/bulk-invoice-runs/{run:uuid}` takes **no** `page`, `offset`, `cursor` or `after`
parameter — nothing in the route, nothing in the request class (there isn't one for `show`), and
nothing in `resources/js/services/bulk-invoice-runs.ts`, whose `showUrl(uuid)` builds the URL from the
wayfinder action with no query at all.

So on a bucket of 250 rows the endpoint returns rows 1–200 and `truncated: true`, and rows 201–250 are
reachable by no request this application can make. `total` is honest (it is counted separately, from a
`GROUP BY`), so the screen correctly says *"Showing the first 200 — this list is cut"* — and offers
nothing.

**Announcing a cut is not the same as being able to read past it**, and the announcement is what makes
this worth a ticket rather than a note: an operator is now told, in words, that there is more, on a
screen with no control that reaches it. The opening-balance report has the same 200-row cap and does
have a way past — `report()` streams the full set as a CSV — so the pattern this screen copied is
missing its escape hatch.

## Why it bites the one list that matters

The cap is per bucket, and that was chosen so a large `billed` bucket cannot push the actionable list
off the payload. But the actionable list is `unplaceable`, and **`unplaceable` is School-wide, not
cohort-wide** (`BillableEnrollmentProvider::listUnplaceableForSchool()`). A school with 250 students
carrying a null term or class level — an import that went in without coordinates is exactly how that
happens — has an unplaceable bucket over the cap on its first run, and the students who cannot be
billed at all are the ones the screen truncates.

`billed` is the bucket where truncation costs least, and `unplaceable` is where it costs most.

## Options

1. **A CSV per bucket**, the way `OpeningBalanceBatchController::report()` already does it — rendered
   on demand, no stored artifact, no schema. Cheapest, matches an existing pattern in the same module,
   and gives the operator something they can hand to whoever fixes the academic records.
2. **A cursor on `show`** — `?bucket=unplaceable&after=<id>`. Correct and more work: the screen grows
   per-bucket paging state and the payload shape changes for all four.
3. **Raise the cap.** Not a fix — it moves the number.

Option 1 closes the actionable half without touching the payload shape.

## What exists today

The suite pins `truncated: false` on small buckets
(`tests/Feature/Finance/BulkInvoiceRunScreenTest.php`, the bucketing arm); **no arm produces a
truncated bucket**, because that needs >200 rows of one outcome, and neither did the browser drive
(`docs/handoff/reports/feat-u6-bulk-run-screen.md` §6g names it as not driven). So the `truncated`
flag itself is unproven in both directions — worth fixing in the same change as whichever option is
taken.
