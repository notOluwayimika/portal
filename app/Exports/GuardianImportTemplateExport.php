<?php

namespace App\Exports;

use App\Services\Validators\GuardianImportRowValidator;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Two-sheet blank import template:
 *   Sheet "Import":  header row matching the validator + one sample row.
 *   Sheet "Columns": one row per column with required flag, format, example, notes.
 */
class GuardianImportTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new GuardianImportTemplateImportSheet(),
            new GuardianImportTemplateColumnsSheet(),
        ];
    }
}

class GuardianImportTemplateImportSheet implements FromArray, WithHeadings, ShouldAutoSize, WithTitle
{
    use Exportable;

    public function array(): array
    {
        $sample = [];
        foreach (GuardianImportRowValidator::COLUMNS as $meta) {
            $sample[] = $meta['example'] ?? '';
        }
        return [$sample];
    }

    public function headings(): array
    {
        return array_keys(GuardianImportRowValidator::COLUMNS);
    }

    public function title(): string
    {
        return 'Import';
    }
}

class GuardianImportTemplateColumnsSheet implements FromArray, WithHeadings, ShouldAutoSize, WithTitle
{
    use Exportable;

    public function array(): array
    {
        $rows = [];
        foreach (GuardianImportRowValidator::COLUMNS as $column => $meta) {
            $rows[] = [
                $column,
                $meta['group']    ?? '',
                $meta['required'] ? 'Yes' : 'No',
                $meta['format']   ?? '',
                $meta['example']  ?? '',
                $meta['notes']    ?? '',
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
