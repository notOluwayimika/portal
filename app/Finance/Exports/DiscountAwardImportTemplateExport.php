<?php

namespace App\Finance\Exports;

use App\Finance\Services\DiscountAwardImporter;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;

/**
 * THE TEMPLATE THE PLATFORM ISSUES for the BSS discount-award list.
 *
 * Brookstone downloads this from the portal. Nobody hand-authors a spreadsheet and emails it, for
 * the reason R13 gives one feature over: a second copy of a format drifts silently, and the drift is
 * only discovered after a data team has already filled a file in against the older copy.
 *
 * SO THIS FILE RENDERS {@see DiscountAwardImporter::COLUMNS}; it never restates it. Every heading and
 * every sample cell is read from that constant at generation time, so a column added, renamed or
 * given different guidance reaches the download with no edit here — and, more to the point, cannot
 * reach the download WITHOUT reaching the reader that parses it back.
 *
 * A SINGLE-SHEET CSV, not a workbook, and that is a correction already paid for once: the
 * opening-balance template shipped as a three-sheet `.xlsx` above an upload that accepts CSV only,
 * so the button handed the operator a file the screen beside it refused. The format this issues and
 * the format the importer accepts are the same format.
 *
 * THE GUIDANCE DID NOT DIE WITH THE OTHER SHEETS, IT MOVED. `COLUMNS`' own `format` / `notes` and
 * {@see DiscountAwardImporter::NOTES} are rendered on the upload screen from the same constants. A
 * CSV cannot carry them, and they hold the rules behind the expensive failures — a rule that lives
 * only in a document is a rule the person filling in the file never sees.
 *
 * THE StringValueBinder STAYS, and it is load-bearing on THIS template specifically. Written through
 * PhpSpreadsheet's `DefaultValueBinder`, an admission number of digits is cast to a NUMBER and loses
 * its leading zeros — `00123` becomes `123` — which is the single most common way a correct list
 * arrives broken, and the one defect `COLUMNS['admission_number']['notes']` warns about. The reason
 * the zeros survive today is that `config/excel.php` binds `Maatwebsite\Excel\DefaultValueBinder`,
 * which preserves strings; this binder is what makes the template independent of that setting rather
 * than quietly dependent on it.
 */
class DiscountAwardImportTemplateExport extends StringValueBinder implements FromArray, WithCustomValueBinder, WithHeadings
{
    use Exportable;

    /**
     * The sample rows, as SPARSE OVERRIDES on the COLUMNS map's own `example` values: a cell named
     * here is written verbatim, a cell absent falls back to that column's example. A column added to
     * the map therefore appears in every sample row carrying its own example, with no edit here.
     *
     * TWO ROWS, AND THEY DIFFER ON THE AXIS THAT COSTS MONEY. A one-row sample — or two rows both
     * saying TUITION ONLY — would teach that the third column is a constant to be copied down, which
     * is precisely the misreading that turns "100% of tuition" into "100% of everything" for a whole
     * cohort. The percentages differ too, for the same reason at lower stakes: a sample where every
     * figure is 50 invites a sheet where every figure is 50.
     *
     * The two phrases here are {@see DiscountAwardImporter::APPLIES_TO_CANONICAL}'s, so a template
     * offering a phrase the reader would refuse is not expressible.
     *
     * @var list<array<string, string>>
     */
    public const SAMPLE_ROWS = [
        [
            'admission_number' => 'STU2025001',
            'discount_percentage' => '50',
            'discount_applies_to' => 'TUITION ONLY',
        ],
        [
            'admission_number' => 'STU2025002',
            'discount_percentage' => '100',
            'discount_applies_to' => 'THE WHOLE BILL',
        ],
    ];

    /**
     * The sample rows rendered against the COLUMNS map's keys, so the headings and the samples cannot
     * fall out of step with each other or with the reader that parses the file back.
     */
    public function array(): array
    {
        $rows = [];

        foreach (self::SAMPLE_ROWS as $sample) {
            $row = [];
            foreach (array_keys(DiscountAwardImporter::COLUMNS) as $column) {
                // array_key_exists, not ??: a sample cell deliberately left BLANK must stay blank,
                // and `?? $meta['example']` would silently refill it — turning a row that
                // demonstrates something into a row that demonstrates nothing.
                $row[] = array_key_exists($column, $sample)
                    ? $sample[$column]
                    : DiscountAwardImporter::COLUMNS[$column]['example'];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The COLUMNS map's keys, in the map's order — which is the order the reader expects to find them
     * announced in the header row it requires.
     *
     * NO COMMENT OR TITLE ROWS ABOVE THIS. The reader takes the FIRST line as the header and counts
     * every line after it; its reader-accounting throw exists precisely to stop drop paths being
     * added, so a decorative line here would be a parse error rather than a nicety.
     */
    public function headings(): array
    {
        return array_keys(DiscountAwardImporter::COLUMNS);
    }
}
