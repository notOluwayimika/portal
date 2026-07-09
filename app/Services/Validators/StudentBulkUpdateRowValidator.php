<?php

namespace App\Services\Validators;

use App\Enums\GenderTypeEnum;
use Illuminate\Support\Carbon;

class StudentBulkUpdateRowValidator
{
    public const COLUMNS = [
        'code' => [
            'required' => true,
            'format'   => 'string',
            'example'  => '20201004',
            'notes'    => 'Student admission number (must exist in this school). Used to find the student.',
            'group'    => 'Lookup',
        ],
        'admission_date' => [
            'required' => false,
            'format'   => 'YYYY-MM-DD or DD/MM/YYYY',
            'example'  => '2020-09-05',
            'notes'    => 'Date the student was admitted.',
            'group'    => 'Details',
        ],
        'sport_house' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Emerald',
            'notes'    => 'Sport house name (must exist in this school). Matched case-insensitively.',
            'group'    => 'Details',
        ],
        'scholarship' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'BSS',
            'notes'    => 'Scholarship name (must exist in this school). Matched case-insensitively. Leave blank to clear.',
            'group'    => 'Details',
        ],
        'nationality' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Nigerian',
            'notes'    => '',
            'group'    => 'Details',
        ],
        'state_of_origin' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Rivers State',
            'notes'    => '',
            'group'    => 'Details',
        ],
        'religion' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Christianity',
            'notes'    => '',
            'group'    => 'Details',
        ],
        'previous_school' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Hopespring Foundation School',
            'notes'    => '',
            'group'    => 'Details',
        ],
        'address' => [
            'required' => false,
            'format'   => 'string',
            'example'  => '15 Onne Road, GRA Phase 2, Port Harcourt',
            'notes'    => '',
            'group'    => 'Details',
        ],
        'gender' => [
            'required' => false,
            'format'   => 'male|female|other',
            'example'  => 'male',
            'notes'    => 'Accepts m/f/o variations.',
            'group'    => 'Personal',
        ],
        'date_of_birth' => [
            'required' => false,
            'format'   => 'YYYY-MM-DD or DD/MM/YYYY',
            'example'  => '2008-03-15',
            'notes'    => '',
            'group'    => 'Personal',
        ],
        'middle_name' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Michael',
            'notes'    => '',
            'group'    => 'Personal',
        ],
        'other_nationality' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'British',
            'notes'    => 'If the student holds dual nationality.',
            'group'    => 'Personal',
        ],
    ];

    /**
     * @return array{errors: string[], normalized: array<string, mixed>, updatable: array<string, mixed>}
     */
    public function validate(array $row): array
    {
        $errors     = [];
        $normalized = [];

        foreach (array_keys(self::COLUMNS) as $col) {
            $val = $row[$col] ?? null;
            if (is_string($val)) {
                $val = trim($val);
                if ($val === '') $val = null;
            }
            $normalized[$col] = $val;
        }

        if ($normalized['code'] === null || $normalized['code'] === '') {
            $errors[] = 'code is required.';
        }

        if ($normalized['admission_date'] !== null) {
            $parsed = $this->parseDate($normalized['admission_date']);
            if ($parsed === null) {
                $errors[] = 'admission_date must be a valid date (YYYY-MM-DD or DD/MM/YYYY).';
            } else {
                $normalized['admission_date'] = $parsed;
            }
        }

        if ($normalized['date_of_birth'] !== null) {
            $parsed = $this->parseDate($normalized['date_of_birth']);
            if ($parsed === null) {
                $errors[] = 'date_of_birth must be a valid date (YYYY-MM-DD or DD/MM/YYYY).';
            } else {
                $normalized['date_of_birth'] = $parsed;
            }
        }

        if ($normalized['gender'] !== null) {
            $g = GenderTypeEnum::normalizeGender((string) $normalized['gender']);
            if ($g === '') {
                $errors[] = 'gender must be male, female, or other.';
                $normalized['gender'] = null;
            } else {
                $normalized['gender'] = $g;
            }
        }

        $updatable = [];
        $fieldMap = [
            'admission_date'    => 'admission_date',
            'nationality'       => 'nationality',
            'state_of_origin'   => 'state_of_origin',
            'religion'          => 'religion',
            'previous_school'   => 'previous_school',
            'address'           => 'address',
            'gender'            => 'gender',
            'date_of_birth'     => 'date_of_birth',
            'middle_name'       => 'middle_name',
            'other_nationality' => 'other_nationality',
        ];

        foreach ($fieldMap as $excelCol => $dbCol) {
            if ($normalized[$excelCol] !== null) {
                $updatable[$dbCol] = $normalized[$excelCol];
            }
        }

        return ['errors' => $errors, 'normalized' => $normalized, 'updatable' => $updatable];
    }

    private function parseDate(mixed $value): ?string
    {
        if (is_numeric($value)) {
            return $this->excelSerialToDate($value);
        }

        $raw = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $raw)) {
            try {
                return Carbon::createFromFormat('d/m/Y', $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function excelSerialToDate(mixed $serial): ?string
    {
        try {
            $unix = ($serial - 25569) * 86400;
            return Carbon::createFromTimestamp($unix)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
