<?php

namespace App\Exports;

use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BroadsheetExport implements FromArray, WithEvents, WithTitle
{
    private const FIXED_COLUMNS = ['S/N', 'Student Name', 'Class', 'Gender'];

    public function __construct(private array $data) {}

    public function array(): array
    {
        return [];
    }

    public function title(): string
    {
        $type = $this->data['is_ccm'] ? 'CCM' : 'End of Term';

        return Str::limit($this->data['class_level'].' '.$type, 31, '');
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $this->buildSheet($event->sheet->getDelegate());
            },
        ];
    }

    private function buildSheet(Worksheet $sheet): void
    {
        $subjects = $this->data['subjects'];
        $isCategorical = ($this->data['grading_mode'] ?? 'numeric') === 'categorical';
        $totalCols = count(self::FIXED_COLUMNS)
            + array_sum(array_map(fn ($s) => count($s['columns']), $subjects))
            + ($isCategorical ? 0 : 1);

        $lastCol = Coordinate::stringFromColumnIndex($totalCols);

        // Title rows
        $sheet->setCellValue('A1', strtoupper($this->data['school_name']));
        $sheet->setCellValue('A2', $this->data['term']['full_name'].' session');
        $type = $this->data['is_ccm'] ? 'CCM' : 'End of Term';
        $sheet->setCellValue('A3', "{$this->data['class_level']} {$type} Broadsheet");

        foreach ([1, 2, 3] as $row) {
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        }
        $sheet->getStyle('A1')->getFont()->setSize(14);

        // Header rows
        $headerRow1 = 5;
        $headerRow2 = 6;

        $col = 1;
        foreach (self::FIXED_COLUMNS as $label) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letter}{$headerRow1}", $label);
            $sheet->mergeCells("{$letter}{$headerRow1}:{$letter}{$headerRow2}");
            $col++;
        }

        foreach ($subjects as $subject) {
            $startCol = $col;
            $endCol = $col + count($subject['columns']) - 1;
            $startLetter = Coordinate::stringFromColumnIndex($startCol);

            $sheet->setCellValue("{$startLetter}{$headerRow1}", $subject['name']);

            if ($endCol > $startCol) {
                $endLetter = Coordinate::stringFromColumnIndex($endCol);
                $sheet->mergeCells("{$startLetter}{$headerRow1}:{$endLetter}{$headerRow1}");
            } else {
                $sheet->mergeCells("{$startLetter}{$headerRow1}:{$startLetter}{$headerRow2}");
            }

            foreach ($subject['columns'] as $colDef) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $sheet->setCellValue("{$letter}{$headerRow2}", $colDef['label']);
                $col++;
            }
        }

        if (! $isCategorical) {
            $gpaLetter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$gpaLetter}{$headerRow1}", 'Term GPA');
            $sheet->mergeCells("{$gpaLetter}{$headerRow1}:{$gpaLetter}{$headerRow2}");
        }

        $headerRange = "A{$headerRow1}:{$lastCol}{$headerRow2}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D9E1F2');
        $sheet->getStyle($headerRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Data rows
        $row = $headerRow2 + 1;
        foreach ($this->data['classes'] as $class) {
            foreach ($class['students'] as $student) {
                $col = 1;
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++).$row, $student['sn']);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++).$row, $student['name']);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++).$row, $class['label']);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++).$row, $student['gender']);

                foreach ($subjects as $subject) {
                    $cell = $student['subjects'][(string) $subject['subject_id']] ?? [];

                    foreach ($subject['columns'] as $colDef) {
                        $value = $cell[$colDef['key']] ?? null;
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++).$row, $value ?? '');
                    }
                }

                if (! $isCategorical) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col++).$row, $student['gpa'] ?? '');
                }

                $row++;
            }
        }

        $lastRow = $row - 1;

        if ($lastRow >= $headerRow2 + 1) {
            $dataRange = 'A'.($headerRow2 + 1).":{$lastCol}{$lastRow}";
            $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle($dataRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $nameRange = 'B'.($headerRow2 + 1).":B{$lastRow}";
            $sheet->getStyle($nameRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        for ($i = 1; $i <= $totalCols; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($letter)->setAutoSize($i > 2 ? true : false);
        }
        $sheet->getColumnDimension('B')->setWidth(28);
    }
}
