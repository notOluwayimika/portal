<?php

namespace App\Exports;

use App\Services\Validators\StudentBulkUpdateRowValidator;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class StudentBulkUpdateTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new StudentBulkUpdateTemplateImportSheet(),
            new StudentBulkUpdateTemplateColumnsSheet(),
        ];
    }
}

class StudentBulkUpdateTemplateImportSheet implements FromArray, WithHeadings, ShouldAutoSize, WithTitle
{
    use Exportable;

    public function array(): array
    {
        $sample = [];
        foreach (StudentBulkUpdateRowValidator::COLUMNS as $meta) {
            $sample[] = $meta['example'] ?? '';
        }
        return [$sample];
    }

    public function headings(): array
    {
        return array_keys(StudentBulkUpdateRowValidator::COLUMNS);
    }

    public function title(): string
    {
        return 'Update';
    }
}

class StudentBulkUpdateTemplateColumnsSheet implements FromArray, WithHeadings, ShouldAutoSize, WithTitle
{
    use Exportable;

    public function array(): array
    {
        $rows = [];
        foreach (StudentBulkUpdateRowValidator::COLUMNS as $column => $meta) {
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
