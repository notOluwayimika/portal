<?php

namespace App\Imports;

use App\Models\Import;
use App\Services\GuardianImportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Top-level guardian import: only the FIRST sheet is processed. Any additional
 * sheets in the uploaded workbook (e.g. the "Columns" reference sheet shipped
 * with our template) are intentionally ignored to avoid double-processing.
 */
class GuardianImport implements WithMultipleSheets
{
    private GuardianImportSheet $sheet;

    public function __construct(Import $import, GuardianImportService $service)
    {
        $this->sheet = new GuardianImportSheet($import, $service);
    }

    public function sheets(): array
    {
        // Index 0 keeps this name-agnostic — works with any first sheet name.
        return [0 => $this->sheet];
    }

    /** @return array<int, array<string, mixed>> */
    public function getResults(): array
    {
        return $this->sheet->getResults();
    }
}
