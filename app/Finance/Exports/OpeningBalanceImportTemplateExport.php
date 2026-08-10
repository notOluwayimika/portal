<?php

namespace App\Finance\Exports;

use App\Finance\Console\ImportOpeningBalances;
use App\Finance\Services\OpeningBalanceFileValidator;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * §9 step 5b-i (R13) — THE TEMPLATE THE PLATFORM ISSUES for the WCBS opening-balance extract.
 *
 * Brookstone downloads this from the portal. Nobody hand-authors a spreadsheet and emails it, because
 * that is a SECOND SOURCE OF TRUTH for a money format — and the guardian import already stated the
 * reason better than a new argument would: "the COLUMNS map drives both the template generator and the
 * row validator, so they cannot drift apart" (GuardianImportRowValidator.php:15-19).
 *
 * SO THIS FILE RENDERS {@see ImportOpeningBalances::COLUMNS}; it never restates it. Every heading and
 * every sample cell is read from that constant at generation time, so a column added, renamed, made
 * optional or given a different limit reaches the download with no edit here.
 *
 * IT IS A SINGLE-SHEET CSV, AND IT USED TO BE A THREE-SHEET WORKBOOK. §9 step 5b-iii shipped the
 * operator screen, whose upload accepts CSV only — because that is what the validator's `read()`
 * parses, with `fgetcsv` and a strict line accounting. So the button handed the operator an `.xlsx`
 * and the upload on the SAME SCREEN refused it. Before that screen existed there was no upload to
 * refuse it, which is the only reason this survived 5b-i: the template was never carried through to
 * the thing that reads it. One download, in the one format the importer accepts.
 *
 * THE OTHER TWO SHEETS DID NOT DIE, THEY MOVED. "Columns" and "Notes" are rendered on the operator
 * screen from the same COLUMNS map and from {@see self::NOTES}. A CSV cannot carry them, and the
 * rules they hold are the ones behind the expensive failures — a rule that lives only in a document
 * is a rule the person filling in the file never sees.
 *
 * THE StringValueBinder STAYS, AND THAT WAS MEASURED RATHER THAN ASSUMED. "A CSV carries text
 * verbatim" is FALSE: written through PhpSpreadsheet's own `DefaultValueBinder`, `120000.00` reaches
 * the CSV as `120000` and `-5000.00` as `-5000`, exactly as it would in a spreadsheet cell — the
 * decimals the format demands vanish from the one place a reader looks for them. The reason the
 * decimals survive today is that `config/excel.php` binds `Maatwebsite\Excel\DefaultValueBinder`,
 * which preserves strings; this binder is what makes the template independent of that config setting
 * rather than quietly dependent on it. Dropping it would be safe today and silently wrong the day
 * someone changes one line of config.
 */
class OpeningBalanceImportTemplateExport extends StringValueBinder implements FromArray, WithCustomValueBinder, WithHeadings
{
    use Exportable;

    /**
     * The sample rows, as SPARSE OVERRIDES on the COLUMNS map's own `example` values: a cell named
     * here is written verbatim, a cell absent falls back to that column's example. So a column added
     * to the map appears in every sample row carrying its own example, with no edit to this array —
     * the same derivation the headings and the Columns sheet rely on.
     *
     * WHY PLURAL, AND WHY THIS SHAPE — RESTATED, because the single-column cutover made the previous
     * version of this paragraph false and it sat 90 lines above a NOTE saying the opposite.
     *
     * It used to read that a one-row sample "teaches the reader that one row per student is the shape
     * — which is the exact mistake §1's L1 and the (student, fee type) key exist to refuse". That is
     * no longer true and must not be repeated: `fee_type_label` and `student_total_balance` are
     * OPTIONAL, one row per student is the LEGAL and expected shape for an extract that cannot split
     * (Brookstone's does not), and the NOTES below instruct the operator to produce exactly that. L1
     * is not-applicable on such a file rather than a refusal, and the (student, fee type) key still
     * refuses a SECOND row for one student — which under the fallback label is the same statement as
     * "one closing balance per student", not a contradiction of it.
     *
     * The sample stays PLURAL for the reason that survives: it is the only way a template can
     * demonstrate the SPLITTABLE form — `student_total_balance` repeating identically across a
     * student's rows — which a one-row sample structurally cannot show. It teaches the richer shape;
     * the NOTES teach the simpler one and say plainly when to use it.
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
            'A blank BALANCE is not a zero',
            'The balance cell must carry a figure on every row. If a balance really is nil, write 0.00 — a '
                .'blank REJECTS the row rather than being read as zero, and nothing is ever coerced or '
                .'corrected on your behalf. This is about `balance` specifically: student_total_balance and '
                .'fee_type_label are OPTIONAL and a blank in either is read as "not supplied", never as zero.',
        ],
        [
            'The control total is NOT in this file',
            'The closing position of EVERY student added up — read off WCBS\'s own report and TYPED IN at '
                .'upload, by the person uploading. That is the whole point of the figure: a total carried '
                .'inside the file was produced by the same export run as the rows, so a student dropped on the '
                .'way out of WCBS disappears from both and the check still passes. Signed, on the same '
                .'convention as `balance` — a school whose families are mostly in credit types a NEGATIVE '
                .'control total. Do not add a total row or a total column.',
        ],
        [
            'One file per school',
            'A school gets ONE posted opening-balance batch, enforced at the database, and posting cannot be '
                .'undone or re-opened. So one file per school, covering EVERY student with a position to carry '
                .'forward — not one file per class, per term or per fee type. A student missing from the file '
                .'starts at zero.',
        ],
        [
            'One row per student PER FEE TYPE — if you can split',
            'A student with three fee types has three rows, and student_total_balance carries the SAME figure '
                .'on all three. See the Import sheet: the first two rows are one student. The totals are what '
                .'prove no line of theirs went missing, so they are checked and a mismatch rejects that '
                .'student\'s rows entirely — never part of them. IF YOUR EXTRACT CANNOT SPLIT the closing '
                .'figure, leave fee_type_label and student_total_balance out and give ONE row per student: '
                .'every line is then filed under "'.OpeningBalanceFileValidator::FALLBACK_FEE_TYPE_LABEL.'". '
                .'Two rows for the same student are refused in that case, because a second closing balance for '
                .'one student is a defect in the file, not an instruction to add them together.',
        ],
        [
            'WHAT THE SIGN MEANS — read this before you type a control total',
            OpeningBalanceFileValidator::SIGN_CONVENTION.' Get this backwards and every check in this import '
                .'still passes: the control total is the sum of the same column, so an inverted file matches '
                .'its own total perfectly, and a posted batch can never be un-posted. Before the batch is '
                .'approved the portal states what it thinks your file says — how many families are in credit '
                .'and for how much, how many are in arrears, and the school\'s net position. Read that sentence '
                .'and refuse it if it is wrong. It is the only check that can catch this.',
        ],
        [
            'Excel destroys leading zeros in admission numbers',
            'Format the admission_number column as TEXT before you type or paste into it. Excel treats a cell '
                .'of digits as a NUMBER and silently drops any leading zeros, so 00123 is saved as 123 — and we '
                .'cannot detect that it happened, because 123 is a perfectly plausible admission number. The '
                .'row then fails to match a student, or worse, matches the wrong one. This is the single most '
                .'common way a correct extract arrives broken.',
        ],
        [
            'Extra columns are ignored',
            'A column this format does not name is read past without comment — WCBS\'s "Last Amended On" is the '
                .'one you will have. It is NOT a payment date: it records when the record was last touched, '
                .'which may have been an adjustment, a correction or nothing to do with money, so the portal '
                .'will not map it onto one. Leave it in the file if it is easier; nothing reads it.',
        ],
    ];

    /**
     * The sample rows, rendered against the COLUMNS map's keys so the headings and the samples cannot
     * fall out of step with each other or with the validator that reads the file back.
     */
    public function array(): array
    {
        $rows = [];

        foreach (self::SAMPLE_ROWS as $sample) {
            $row = [];
            foreach (ImportOpeningBalances::COLUMNS as $column => $meta) {
                // array_key_exists, not ??: a sample cell deliberately left BLANK (the optional
                // reference) must stay blank, and `?? $meta['example']` would silently refill it with
                // the example — turning the one row that demonstrates "optional" back into a row that
                // demonstrates nothing.
                $row[] = array_key_exists($column, $sample) ? $sample[$column] : $meta['example'];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The COLUMNS map's keys, in the map's order — which is also the order `read()` expects to find
     * them announced in the header row it requires.
     *
     * NO COMMENT OR TITLE ROWS ABOVE THIS. The reader takes the FIRST line as the header and counts
     * every line after it; its reader-accounting throw exists precisely to stop drop paths being
     * added, so a decorative line here would be a parse error rather than a nicety.
     */
    public function headings(): array
    {
        return array_keys(ImportOpeningBalances::COLUMNS);
    }
}
