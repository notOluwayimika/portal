<?php

namespace App\Finance\Exports;

use App\Exports\GuardianImportTemplateExport;
use App\Finance\Console\ImportOpeningBalances;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * §9 step 5b-i (R13) — THE TEMPLATE THE PLATFORM ISSUES for the WCBS opening-balance extract.
 *
 * Brookstone downloads this from the portal. Nobody hand-authors a spreadsheet and emails it, because
 * that is a SECOND SOURCE OF TRUTH for a money format — and the guardian import already stated the
 * reason better than a new argument would: "the COLUMNS map drives both the template generator and the
 * row validator, so they cannot drift apart" (GuardianImportRowValidator.php:15-19).
 *
 * SO THIS FILE RENDERS {@see ImportOpeningBalances::COLUMNS}; it never restates it. Every heading,
 * every Columns-sheet row and every sample cell is read from that constant at generation time, so a
 * column added, renamed, made optional or given a different limit reaches the downloaded workbook with
 * no edit here. A template that listed the columns itself would be exactly the second source R13
 * exists to refuse — and the drift it invites is invisible until a data team has already filled in a
 * file against the older copy.
 *
 * THREE SHEETS, and the third is a deliberate departure from the two-sheet guardian template
 * ({@see GuardianImportTemplateExport}):
 *
 *   "Import"  — the headings plus SAMPLE ROWS, PLURAL. See SAMPLE_ROWS for why one row cannot be
 *               enough for this format.
 *   "Columns" — one row per column: Column / Group / Required / Format / Example / Notes, exactly the
 *               guardian sheet's shape (GuardianImportTemplateExport.php:73-76).
 *   "Notes"   — the rules that are NOT per-column and therefore have nowhere to live in that table.
 *               They are the rules behind the EXPENSIVE failures (§11's pure-arrears assumption is
 *               invisible until after money has moved), which is precisely why they must not be the
 *               ones with no home.
 */
class OpeningBalanceImportTemplateExport implements WithMultipleSheets
{
    /**
     * The sample rows, as SPARSE OVERRIDES on the COLUMNS map's own `example` values: a cell named
     * here is written verbatim, a cell absent falls back to that column's example. So a column added
     * to the map appears in every sample row carrying its own example, with no edit to this array —
     * the same derivation the headings and the Columns sheet rely on.
     *
     * WHY PLURAL, AND WHY THIS SHAPE. The guardian template emits ONE sample row, which structurally
     * cannot show this format's central rule: `student_total_balance` REPEATS IDENTICALLY across a
     * student's rows, because the file is one row per (student × fee type). A one-row sample teaches
     * the reader that one row per student is the shape — which is the exact mistake §1's L1 and the
     * (student, fee type) key exist to refuse, and the one that makes an operator's first upload
     * bounce. The most likely mistake must be the one the sample demonstrates.
     *
     * So: STUDENT ONE carries TWO rows whose stated total is the same figure on both and equals the
     * sum of their two balances (120,000.00 + 25,000.00 = 145,000.00 — L1 as an operator will meet
     * it), and one of those rows leaves the OPTIONAL `wcbs_bill_reference` blank, which is the only
     * way a template can show that a blank there does not reject the row (R12). STUDENT TWO carries a
     * NEGATIVE balance, because `balance` is signed by design — positive owed, negative credit — and a
     * sample of only positive figures invites a file that drops the minus signs on every student the
     * school owes money to.
     *
     * The arithmetic is not decoration: OpeningBalanceImportTemplateTest re-derives L1 over these rows
     * with the same Money parsing the validator uses, so a sample edited to a figure that would be
     * REJECTED by the importer fails there rather than being discovered by the data team.
     *
     * @var list<array<string, string>>
     */
    public const SAMPLE_ROWS = [
        [
            'admission_number' => 'STU2025001',
            'wcbs_student_ref' => 'WCBS-10233',
            'fee_type_label' => 'Tuition',
            'balance' => '120000.00',
            'student_total_balance' => '145000.00',
            'wcbs_bill_reference' => 'BILL-2026-0912',
        ],
        [
            'admission_number' => 'STU2025001',
            'wcbs_student_ref' => 'WCBS-10233',
            'fee_type_label' => 'Bus',
            'balance' => '25000.00',
            'student_total_balance' => '145000.00',
            // Deliberately blank: the same student's second row, showing that the OPTIONAL reference
            // may be empty without rejecting the row.
            'wcbs_bill_reference' => '',
        ],
        [
            'admission_number' => 'STU2025002',
            'wcbs_student_ref' => 'WCBS-10412',
            'fee_type_label' => 'Tuition',
            'balance' => '-5000.00',
            'student_total_balance' => '-5000.00',
            'wcbs_bill_reference' => '',
        ],
    ];

    /**
     * The rules that are not about ONE column, so the Columns sheet has nowhere to put them.
     *
     * Each is a rule whose failure is expensive and whose failure is NOT caught by the import: the
     * pure-arrears assumption cannot be checked by any arithmetic (§11 — a contaminated closing
     * balance is byte-identical to a clean one and both checksum levels pass either way), a blank
     * amount is refused rather than read as zero (§2), the control total travels a different path from
     * the file on purpose (§1 L2), and one school gets one posted batch, at the database (G1).
     *
     * @var list<array{0: string, 1: string}>
     */
    public const NOTES = [
        [
            'Arrears only — no new-term fees',
            'Every balance in this file must be the CLOSING position of the term being closed out, and must '
                .'carry none of the new term\'s fees. Nothing in the portal can check this: a balance that '
                .'includes a term\'s fees looks identical to one that does not, and both checksums pass either '
                .'way. If fees are included, the student is billed that term twice and the correction is a '
                .'database restore. Confirm a sample of students against WCBS before the batch is approved.',
        ],
        [
            'A blank is not a zero',
            'Every amount cell must carry a figure. If a balance really is nil, write 0.00 — a blank amount '
                .'REJECTS the row rather than being read as zero, and nothing is ever coerced or corrected on '
                .'your behalf.',
        ],
        [
            'The control total is NOT in this file',
            'The sum of every student_total_balance is read off WCBS\'s own report and TYPED IN at upload, by '
                .'the person uploading. That is the whole point of the figure: a total carried inside the file '
                .'was produced by the same export run as the rows, so a student dropped on the way out of WCBS '
                .'disappears from both and the check still passes. Do not add a total row or a total column.',
        ],
        [
            'One file per school',
            'A school gets ONE posted opening-balance batch, enforced at the database, and posting cannot be '
                .'undone or re-opened. So one file per school, covering EVERY student with a position to carry '
                .'forward — not one file per class, per term or per fee type. A student missing from the file '
                .'starts at zero.',
        ],
        [
            'One row per student PER FEE TYPE',
            'A student with three fee types has three rows, and student_total_balance carries the SAME figure '
                .'on all three. See the Import sheet: the first two rows are one student. The totals are what '
                .'prove no line of theirs went missing, so they are checked and a mismatch rejects that '
                .'student\'s rows entirely — never part of them.',
        ],
    ];

    public function sheets(): array
    {
        return [
            new OpeningBalanceImportTemplateImportSheet,
            new OpeningBalanceImportTemplateColumnsSheet,
            new OpeningBalanceImportTemplateNotesSheet,
        ];
    }
}

/**
 * Sheet 1 — what the operator fills in. Headings are the COLUMNS map's keys in the map's order, and
 * the sample rows are rendered against those same keys, so the two cannot fall out of step with each
 * other or with the validator that reads the file back.
 *
 * EVERY CELL IS WRITTEN AS TEXT (StringValueBinder), and on a money template that is not a detail.
 * The default binder casts a numeric-looking string to a number, and the sample's whole job is to show
 * the FORMAT: '120000.00' would render as 120000 and '-5000.00' as -5000, so the two decimals the file
 * requires would be invisible in the one place a reader looks for them, and an admission number with a
 * leading zero would lose it.
 */
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
                // demonstrates nothing.
                $row[] = array_key_exists($column, $sample) ? $sample[$column] : ($meta['example'] ?? '');
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

/**
 * Sheet 2 — the format, one row per column, in the guardian template's shape and column order
 * (GuardianImportTemplateExport.php:73-76). Every cell is read from the COLUMNS map.
 *
 * `Format` is where the map's `max` reaches the reader, and for `fee_type_label` that number is 229,
 * NOT the storage column's 255: posting appends ' — Balance Brought Forward' (26 characters) to the
 * label verbatim into a varchar(255) narration. A template advertising 255 would produce a file that
 * stages perfectly and then aborts the post at 1406 — on cutover day.
 */
class OpeningBalanceImportTemplateColumnsSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    use Exportable;

    public function array(): array
    {
        $rows = [];

        foreach (ImportOpeningBalances::COLUMNS as $column => $meta) {
            $rows[] = [
                $column,
                $meta['group'] ?? '',
                $meta['required'] ? 'Yes' : 'No',
                $meta['format'] ?? '',
                $meta['example'] ?? '',
                $meta['notes'] ?? '',
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

/** Sheet 3 — the rules with no column to live on. See OpeningBalanceImportTemplateExport::NOTES. */
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
