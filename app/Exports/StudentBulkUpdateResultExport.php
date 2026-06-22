<?php

namespace App\Exports;

use App\Services\Validators\StudentBulkUpdateRowValidator;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentBulkUpdateResultExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function __construct(private array $rows) {}

    public function array(): array
    {
        $headers = $this->headings();
        $out = [];

        foreach ($this->rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            $out[] = $line;
        }

        return $out;
    }

    public function headings(): array
    {
        return array_merge(
            array_keys(StudentBulkUpdateRowValidator::COLUMNS),
            ['update_status', 'update_message'],
        );
    }
}
