# §9 step 5b-i — the platform issues the opening-balance import template

Branch `feat/finance-ob-import-template`, base `origin/staging` @ `e510765` (PR #219, the 5a merge),
two commits. R13 is the ruling this implements: *"Brookstone downloads it from the portal; we do not
email a spreadsheet and hope it survives being opened, re-saved and forwarded."*

**This is full-review tier** — it adds an RBAC-gated route and ships a document that leaves the
building. Recommend a cold session before merge.

---

## Headline

Done. `GET /api/v1/finance/opening-balance-batches/import/template` returns a three-sheet workbook
rendered from `ImportOpeningBalances::COLUMNS`, gated on the maker ability
`finance.opening-balance.submit`. Twelve test arms, every one asserting against the **generated
file** loaded back through PhpSpreadsheet rather than against the export's own arrays. All four
required bite-proofs went red on demand and are pasted raw below. `bin/quality` passes all 14 steps.

One deviation from the brief, one thing the brief could not have known, and one retirement to
announce — all three below.

---

## Deviation: the route noun

The brief wrote `GET .../opening-balances/import/template`. The route ships as:

```
GET /api/v1/finance/opening-balance-batches/import/template
```

`opening-balance-batches` is the noun this feature already answers at — `…/opening-balance-batches/pending`
has been there since 5a (`routes/endpoints/finance.php:146`) — and the spec itself only says
`GET .../import/template` (`docs/handoff/opening-balance-import-spec.md:278`), leaving the prefix to
the repo. Adding `opening-balances` beside it would give one feature two nouns in the same route
file, and 5b-ii's upload would then have to pick one. Path shape is otherwise identical to
`GET /api/guardians/import/template` (`routes/endpoints/guardian.php:16`).

If the project lead wants the brief's noun instead, it is a one-line change plus the two URLs in the
test; say so and it moves.

---

## What was built

### 1. The export renders the map; it does not restate it

`app/Finance/Exports/OpeningBalanceImportTemplateExport.php`. Every heading, every Columns-sheet row
and every sample cell is read from `ImportOpeningBalances::COLUMNS` at generation time. That is the
whole of R13: the guardian import's validator already carries the reason — *"the COLUMNS map drives
both the template generator and the row validator, so they cannot drift apart"*
(`app/Services/Validators/GuardianImportRowValidator.php:15-19`) — and a template that listed the
columns itself would be the second source of truth for a money format.

The three sheet definitions, verbatim from the committed file:

**Sheet 1 — Import**

```php
class OpeningBalanceImportTemplateImportSheet extends StringValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithTitle
{
    use Exportable;

    public function array(): array
    {
        $rows = [];

        foreach (OpeningBalanceImportTemplateExport::SAMPLE_ROWS as $sample) {
            $row = [];
            foreach (ImportOpeningBalances::COLUMNS as $column => $meta) {
                // array_key_exists, not ??: a sample cell deliberately left BLANK (the optional
                // reference) must stay blank, and `?? $meta['example']` would silently refill it with
                // the example — turning the one row that demonstrates "optional" back into a row that
                // demonstrates nothing. The map's own cell needs no fallback either: COLUMNS is typed
                // with every key required, so `?? ''` would be dead code Larastan rejects, and would
                // paper over a malformed entry rather than failing on it.
                $row[] = array_key_exists($column, $sample) ? $sample[$column] : $meta['example'];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function headings(): array
    {
        return array_keys(ImportOpeningBalances::COLUMNS);
    }

    public function title(): string
    {
        return 'Import';
    }
}
```

**Sheet 2 — Columns**

```php
class OpeningBalanceImportTemplateColumnsSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    use Exportable;

    public function array(): array
    {
        $rows = [];

        foreach (ImportOpeningBalances::COLUMNS as $column => $meta) {
            $rows[] = [
                $column,
                $meta['group'],
                $meta['required'] ? 'Yes' : 'No',
                $meta['format'],
                $meta['example'],
                $meta['notes'],
            ];
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['Column', 'Group', 'Required', 'Format', 'Example', 'Notes'];
    }

    public function title(): string
    {
        return 'Columns';
    }
}
```

**Sheet 3 — Notes**

```php
class OpeningBalanceImportTemplateNotesSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    use Exportable;

    public function array(): array
    {
        return array_map(
            fn (array $note) => [$note[0], $note[1]],
            OpeningBalanceImportTemplateExport::NOTES,
        );
    }

    public function headings(): array
    {
        return ['Rule', 'What it means'];
    }

    public function title(): string
    {
        return 'Notes';
    }
}
```

### 2. Sample rows, plural — and why each one is there

`SAMPLE_ROWS` is a list of **sparse overrides** on the map's own `example` values: a cell named there
is written verbatim, a cell absent falls back to that column's example. So a column added to the map
appears in every sample row carrying its own example, with no edit to the array — proved below.

- **Student one carries TWO rows** (`Tuition` 120000.00, `Bus` 25000.00) with `student_total_balance`
  = 145000.00 on both. A one-row sample teaches that one row per student is the shape, which is
  exactly what §1's L1 and the `(student, fee type)` key refuse. The most likely mistake is the one
  the sample demonstrates.
- **Its second row leaves `wcbs_bill_reference` blank** — the only way a template can show that a
  blank there does not reject the row (R12).
- **Student two carries a NEGATIVE balance** (−5000.00, total −5000.00). `balance` is signed by
  design; a positives-only sample invites a file that drops the minus signs on every student the
  school owes.

**Not in the brief, and load-bearing on a money template: the Import sheet writes cells as TEXT**
(`StringValueBinder` + `WithCustomValueBinder`). The default binder casts `'120000.00'` to the number
120000 and `'-5000.00'` to −5000, so the two decimals the format requires would be invisible in the
one place a reader looks for them, and an admission number with a leading zero would lose it. One
test arm pins the rendered cells against `/^-?\d+\.\d{2}$/`.

### 3. The route

```php
Route::get('/v1/finance/opening-balance-batches/import/template', [OpeningBalanceBatchController::class, 'template'])
    ->middleware('permission:finance.opening-balance.submit');
```

The MAKER half of §9 step 4c's triple (`app/Enums/Permission.php:158-160`) — the person who downloads
the template is the person who will upload the file. Nothing is coined; the checker's `…approve`
(which gates `pending`) is deliberately **not** admitted, and neither is `finance.access` alone.

No fixture oracle needed regeneration: both route oracles are deliberately asymmetric and let a NEW
guarded route through (`tests/Feature/Rbac/RouteMiddlewareBaselineTest.php:14-18`,
`tests/Feature/Rbac/RouteAccessParityTest.php:18-22`). No new permission, so no `rbac:sync`, no
grants change, no migration.

---

## The four bite-proofs — watched red first, raw

### 1. The sample rows obey §1's L1

Planted: student one's `Bus` balance `25000.00` → `26000.00`, leaving the stated total at 145000.00.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":2,"duration_ms":19714,"failed":1,"failures":[{"test":"P\\Tests\\Feature\\Finance\\OpeningBalanceImportTemplateTest::__pest_evaluable_it_samples_rows_§1_L1_WOULD_ACCEPT_—_one_stated_total_per_student__equal_to_the_sum_of_that_student_s_balances","file":"/Users/mac/Documents/Projects/portal/tests/Feature/Finance/OpeningBalanceImportTemplateTest.php","line":134,"message":"student [STU2025001]: Σ of the sampled balances does not equal the sampled student_total_balance — the template ships a sample its own importer rejects\nFailed asserting that 14600000 is identical to 14500000."}]}
```

Restored → green:

```
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":4,"duration_ms":22089}
```

### 2. The Columns sheet is DERIVED

Two halves, because "the sheet gained a row" only means something if the assertion would have caught
the opposite.

**(a) A column added to the map reaches the workbook with no edit to the export.** Planted a seventh
entry `wcbs_class_label` in `ImportOpeningBalances::COLUMNS`. The Columns test stayed green:

```
{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":3,"duration_ms":24481}
```

and the generated workbook gained the column in **both** sheets — the Import sheet heading with its
example filled into every sample row, and a new Columns row — with the export untouched:

```
=== SHEET 1: Import ===
admission_number | wcbs_student_ref | fee_type_label | balance | student_total_balance | wcbs_class_label | wcbs_bill_reference
STU2025001 | WCBS-10233 | Tuition | 120000.00 | 145000.00 | Year 7 Blue | BILL-2026-0912
STU2025001 | WCBS-10233 | Bus | 25000.00 | 145000.00 | Year 7 Blue |
STU2025002 | WCBS-10412 | Tuition | -5000.00 | -5000.00 | Year 7 Blue |

=== SHEET 2: Columns ===
…
wcbs_class_label | Provenance | No | string, max 100 characters | Year 7 Blue | Temporary bite-proof column.
wcbs_bill_reference | Provenance | No | string, max 255 characters | BILL-2026-0912 | OPTIONAL. The reference on the last paper bill, if WCBS carries one. A blank here does NOT reject the row.
```

**(b) An export that carries its own column list goes red.** With the seventh column still in the
map, replaced the Import sheet's `headings()` with a literal six-name list and pointed the Columns
sheet at a fixed six-column slice — which is what a hand-authored template is:

```
{"tool":"pest","result":"failed","tests":2,"passed":0,"assertions":3,"duration_ms":21078,"failed":2,"failures":[{"test":"…it_heads_the_Import_sheet_with_the_COLUMNS_map_keys__in_the_map_order","file":"/Users/mac/Documents/Projects/portal/tests/Feature/Finance/OpeningBalanceImportTemplateTest.php","line":91,"message":"Failed asserting that two arrays are identical.\n--- Expected\n+++ Actual\n@@ @@\n     2 => 'fee_type_label',\n     3 => 'balance',\n     4 => 'student_total_balance',\n-    5 => 'wcbs_class_label',\n-    6 => 'wcbs_bill_reference',\n+    5 => 'wcbs_bill_reference',\n+    6 => '',\n ]"},{"test":"…it_renders_the_Columns_sheet_FROM_the_COLUMNS_map_—_one_row_per_column__every_cell_the_map_s_own","file":"/Users/mac/Documents/Projects/portal/tests/Feature/Finance/OpeningBalanceImportTemplateTest.php","line":186,"message":"Failed asserting that two arrays are identical.\n--- Expected\n+++ Actual\n@@ @@\n         4 => 'Year 7 Blue',\n         5 => 'Temporary bite-proof column.',\n     ],\n-    6 => Array &7 [\n-        0 => 'wcbs_bill_reference',\n-        1 => 'Provenance',\n-        2 => 'No',\n-        3 => 'string, max 255 characters',\n-        4 => 'BILL-2026-0912',\n-        5 => 'OPTIONAL. The reference on the last paper bill, if WCBS carries one. A blank here does NOT reject the row.',\n-    ],\n ]"}]}
```

Both restorations verified by `git checkout` of the map and a full-file green run
(`12 passed, 40 assertions`).

### 3. The derived max length reaches the sheet

Planted: `fee_type_label`'s `format` in the map → `'string, max 255 characters'` (the storage
column's own width, which is the wrong number — posting appends a 26-character suffix into a
`varchar(255)` narration, so 229 is the limit and a file built to 255 aborts the post at 1406).

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":17699,"failed":1,"failures":[{"test":"P\\Tests\\Feature\\Finance\\OpeningBalanceImportTemplateTest::__pest_evaluable_it_carries_the_DERIVED_max_length_to_the_reader_—_fee__type__label_says_229__never_the_column_s_255","file":"/Users/mac/Documents/Projects/portal/tests/Feature/Finance/OpeningBalanceImportTemplateTest.php","line":200,"message":"Failed asserting that 'string, max 255 characters' [ASCII](length: 26) contains \"229\" [ASCII](length: 3)."}]}
```

The same arm also holds the general rule the specific case instantiates: every column's `Format` cell
must state the number its `max` really holds.

### 4. The route refuses a user without the maker ability

Planted: `->middleware('permission:finance.opening-balance.submit')` removed from the route.

```
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":19943,"failed":1,"failures":[{"test":"P\\Tests\\Feature\\Finance\\OpeningBalanceImportTemplateTest::__pest_evaluable_it_refuses_a_user_without_finance_opening_balance_submit","file":"/Users/mac/Documents/Projects/portal/vendor/laravel/framework/src/Illuminate/Testing/TestResponseAssert.php","line":45,"message":"Expected response status code [403] but received 200.\nFailed asserting that 200 is identical to 403."}]}
```

The denied actor holds `finance.access` **and** the checker's `finance.opening-balance.approve`, so
the 200 above is the group gate admitting them — which is exactly the hole the maker ability closes.

---

## What I read off the generated file

Not inferred from the arrays: rendered to real xlsx bytes and loaded back through PhpSpreadsheet.
10,765 bytes, sheets in this order.

```
SHEETS, in order: Import, Columns, Notes

=== SHEET 1: Import ===
admission_number | wcbs_student_ref | fee_type_label | balance | student_total_balance | wcbs_bill_reference
STU2025001 | WCBS-10233 | Tuition | 120000.00 | 145000.00 | BILL-2026-0912
STU2025001 | WCBS-10233 | Bus | 25000.00 | 145000.00 |
STU2025002 | WCBS-10412 | Tuition | -5000.00 | -5000.00 |

=== SHEET 2: Columns ===
Column | Group | Required | Format | Example | Notes
admission_number | Linking | Yes | string, max 255 characters | STU2025001 | The join key. Must already exist in this School — a student is NEVER created from a finance import.
wcbs_student_ref | Linking | Yes | string, max 255 characters | WCBS-10233 | WCBS's own id, stored for traceability. Never used to join.
fee_type_label | Amounts | Yes | string, max 229 characters | Tuition | The fee type as WCBS names it, carried verbatim onto the statement. One row per student PER FEE TYPE. Spelling is matched case-insensitively — and also ignoring accents and trailing spaces — so "Tuition", "tuition" and "Tuitión" are ONE fee type, and a second row for it is refused.
balance | Amounts | Yes | naira with two decimal places, SIGNED (120000.00 / -5000.00), max 21 characters | 120000.00 | That fee type's closing balance for that student. POSITIVE is owed, NEGATIVE is credit. Blank is not zero — write 0.00 if the balance really is nil.
student_total_balance | Amounts | Yes | naira with two decimal places, SIGNED, max 21 characters | 145000.00 | The student's total across ALL their fee types. Write the SAME figure on every one of that student's rows — it is the independent check that no line of theirs went missing.
wcbs_bill_reference | Provenance | No | string, max 255 characters | BILL-2026-0912 | OPTIONAL. The reference on the last paper bill, if WCBS carries one. A blank here does NOT reject the row.

=== SHEET 3: Notes ===
Rule | What it means
Arrears only — no new-term fees | Every balance in this file must be the CLOSING position of the term being closed out, and must carry none of the new term's fees. Nothing in the portal can check this: a balance that includes a term's fees looks identical to one that does not, and both checksums pass either way. If fees are included, the student is billed that term twice and the correction is a database restore. Confirm a sample of students against WCBS before the batch is approved.
A blank is not a zero | Every amount cell must carry a figure. If a balance really is nil, write 0.00 — a blank amount REJECTS the row rather than being read as zero, and nothing is ever coerced or corrected on your behalf.
The control total is NOT in this file | The sum of every student_total_balance is read off WCBS's own report and TYPED IN at upload, by the person uploading. That is the whole point of the figure: a total carried inside the file was produced by the same export run as the rows, so a student dropped on the way out of WCBS disappears from both and the check still passes. Do not add a total row or a total column.
One file per school | A school gets ONE posted opening-balance batch, enforced at the database, and posting cannot be undone or re-opened. So one file per school, covering EVERY student with a position to carry forward — not one file per class, per term or per fee type. A student missing from the file starts at zero.
One row per student PER FEE TYPE | A student with three fee types has three rows, and student_total_balance carries the SAME figure on all three. See the Import sheet: the first two rows are one student. The totals are what prove no line of theirs went missing, so they are checked and a mismatch rejects that student's rows entirely — never part of them.
```

Read as a recipient: the sheet **names** and **order** are `Import`, `Columns`, `Notes`; the money
cells keep their two decimals and the credit keeps its minus sign; the optional reference is visibly
blank on two rows; `fee_type_label` advertises **229**, not 255.

---

## RETIRE THE INTERIM

A hand-made CSV and a format note were sent to the project lead before this existed. **The
platform-issued template supersedes both.** Anyone still holding the interim copy should discard it
and download from the portal instead — that is the entire point of R13: a second copy of a money
format drifts silently, and the drift is only discovered after a data team has already filled a file
in against the older one. Please say so in whatever thread the interim was sent on.

---

## Commits

```
d463c28 fix(finance): drop the dead ?? fallbacks on the COLUMNS map cells

M	app/Finance/Exports/OpeningBalanceImportTemplateExport.php
b1d5e50 feat(finance): the platform issues the opening-balance import template

A	app/Finance/Exports/OpeningBalanceImportTemplateExport.php
M	app/Finance/Http/Controllers/OpeningBalanceBatchController.php
M	routes/endpoints/finance.php
A	tests/Feature/Finance/OpeningBalanceImportTemplateTest.php
```

## `bin/quality`

```
quality gate — base e510765

[1/14] dependency integrity (composer.lock vs composer.json vs vendor/)
   ✓ dependency-integrity-lint
[2/14] wayfinder:generate --with-form (must match vite.config.ts formVariants)
   ✓ wayfinder:generate
[3/14] lint changed files (Pint / Prettier / ESLint, check mode)
   ✓ lint-changed
[4/14] types (tsc ratchet vs tsc-baseline)
   ✓ tsc-ratchet
[5/14] frontend build (vite — catches what the tsc ratchet structurally cannot)
   ✓ build
[6/14] authorization guard (no new commented-out checks)
   ✓ authz-lint
[7/14] boundary lint (§17.2)
   ✓ boundary-lint
[8/14] grants-convergence lint (a pre-existing permission added to grantsMap() ships a migration)
   ✓ grants-convergence-lint
[9/14] money lint (UI: money via formatNaira, no JS money math)
   ✓ money-lint
[10/14] runtime-zero lint (S7 legacy access sources)
   ✓ runtime-zero-lint
[11/14] identifier-generation bypass guard (1.4b)
   ✓ identifier-generation-lint
[12/14] architecture tests (§17.1)
   ✓ arch
[13/14] static analysis (Larastan level 5 vs baseline)
   ✓ larastan
[14/14] tests (failure ratchet vs tests/ratchet-baseline.txt)
   ✓ test-ratchet

✓ quality: PASS — per-push floor. Promoting to main? run bin/quality-promote.
```

---

## Not done, deliberately

- **No upload screen.** 5b-ii owns `POST …/import`, the control-total field and the operator's
  submit. Nothing here opens a write path.
- **No `Permission` case, no migration, no validator change.** Read surface only, as scoped; the
  COLUMNS map was read, never edited (the seventh column in bite-proof 2 was planted and reverted —
  `git diff origin/staging -- app/Finance/Console/ImportOpeningBalances.php` is empty).
- **No baseline touched.** `phpstan-baseline.neon` and `tests/ratchet-baseline.txt` are unmodified;
  the one Larastan finding this change produced was fixed in the code (commit 2), not baselined.
- **Not driven in the running app.** The artifact was verified by generating the real workbook and
  reading it back, plus an HTTP arm through the route in both directions. A browser click-through
  needs a logged-in holder of `finance.opening-balance.submit` in the dev database; worth doing
  before the cutover, and it is the one verification this report does not carry.
