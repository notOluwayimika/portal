<?php

namespace App\Exports;

use App\Services\Validators\GuardianImportRowValidator;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Generates the per-row import result report (original columns + status/message/guardian_id).
 */
class GuardianImportResultExport implements FromArray, WithHeadings, ShouldAutoSize
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
            array_keys(GuardianImportRowValidator::COLUMNS),
            ['import_status', 'import_message', 'guardian_id'],
        );
    }
}
