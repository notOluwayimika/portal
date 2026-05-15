<?php

namespace App\Services\Validators;

use App\Enums\GenderTypeEnum;
use App\Enums\GuardianIdTypeEnum;
use App\Enums\GuardianRelationshipEnum;
use App\Enums\GuardianStatusEnum;
use App\Enums\MaritalStatusEnum;
use App\Enums\PreferredContactChannelEnum;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Single source of truth for the guardian import format:
 * the COLUMNS map drives both the template generator and the row validator,
 * so they cannot drift apart.
 */
class GuardianImportRowValidator
{
    public const COLUMNS = [
        // Linking
        'admission_number' => [
            'required' => true,
            'format'   => 'string',
            'example'  => 'STU2025001',
            'notes'    => 'Student admission number (must exist in this school).',
            'group'    => 'Linking',
        ],
        'relationship' => [
            'required' => true,
            'format'   => 'father|mother|guardian|uncle|aunt|grandparent|step_parent|sibling|other',
            'example'  => 'father',
            'notes'    => 'Relationship of guardian to the student.',
            'group'    => 'Linking',
        ],
        'is_primary' => [
            'required' => true,
            'format'   => 'yes/no or true/false',
            'example'  => 'yes',
            'notes'    => 'Whether this guardian is the primary contact for the student.',
            'group'    => 'Linking',
        ],

        // Identity (required to create or match)
        'first_name' => [
            'required' => true,
            'format'   => 'string',
            'example'  => 'John',
            'notes'    => 'Guardian first name.',
            'group'    => 'Identity',
        ],
        'last_name' => [
            'required' => true,
            'format'   => 'string',
            'example'  => 'Doe',
            'notes'    => 'Guardian last name.',
            'group'    => 'Identity',
        ],
        'phone' => [
            'required' => true,
            'format'   => 'string',
            'example'  => '+2348000000000',
            'notes'    => 'Phone number. Used for deduplication; formats are normalized.',
            'group'    => 'Identity',
        ],

        // Personal
        'middle_name' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Michael',
            'notes'    => '',
            'group'    => 'Personal',
        ],
        'gender' => [
            'required' => false,
            'format'   => 'male|female|other',
            'example'  => 'male',
            'notes'    => 'Accepts m/f/o variations.',
            'group'    => 'Personal',
        ],
        'marital_status' => [
            'required' => false,
            'format'   => 'single|married|divorced|widowed|separated',
            'example'  => 'married',
            'notes'    => '',
            'group'    => 'Personal',
        ],
        'email' => [
            'required' => false,
            'format'   => 'email',
            'example'  => 'john.doe@example.com',
            'notes'    => 'Login identifier. Required if can_login = yes. Used for deduplication.',
            'group'    => 'Personal',
        ],

        // Contact
        'whatsapp_number' => [
            'required' => false,
            'format'   => 'phone',
            'example'  => '+2348000000000',
            'notes'    => '',
            'group'    => 'Contact',
        ],
        'emergency_contact' => [
            'required' => false,
            'format'   => 'phone',
            'example'  => '+2348111111111',
            'notes'    => '',
            'group'    => 'Contact',
        ],

        // Address
        'city' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Lagos',
            'notes'    => '',
            'group'    => 'Address',
        ],
        'state' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Lagos',
            'notes'    => '',
            'group'    => 'Address',
        ],
        'country' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Nigeria',
            'notes'    => '',
            'group'    => 'Address',
        ],
        'postal_code' => [
            'required' => false,
            'format'   => 'string',
            'example'  => '100001',
            'notes'    => '',
            'group'    => 'Address',
        ],

        // Employment
        'occupation' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Engineer',
            'notes'    => '',
            'group'    => 'Employment',
        ],
        'employer_name' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'Acme Inc.',
            'notes'    => '',
            'group'    => 'Employment',
        ],

        // ID
        'id_type' => [
            'required' => false,
            'format'   => 'national_id|passport|drivers_license',
            'example'  => 'national_id',
            'notes'    => 'Must be paired with id_number.',
            'group'    => 'Identification',
        ],
        'id_number' => [
            'required' => false,
            'format'   => 'string',
            'example'  => 'A12345678',
            'notes'    => 'Required when id_type is provided.',
            'group'    => 'Identification',
        ],
        'id_expiry_date' => [
            'required' => false,
            'format'   => 'YYYY-MM-DD',
            'example'  => '2030-12-31',
            'notes'    => '',
            'group'    => 'Identification',
        ],

        // Status & access
        'status' => [
            'required' => false,
            'format'   => 'active|inactive|blocked',
            'example'  => 'active',
            'notes'    => 'Defaults to active.',
            'group'    => 'Status & Access',
        ],
        'can_login' => [
            'required' => false,
            'format'   => 'yes/no or true/false',
            'example'  => 'no',
            'notes'    => 'Defaults to no. If yes, an invitation is sent.',
            'group'    => 'Status & Access',
        ],
        'preferred_contact_channel' => [
            'required' => false,
            'format'   => 'email|sms|whatsapp',
            'example'  => 'email',
            'notes'    => 'Channel used for the invitation when can_login = yes.',
            'group'    => 'Status & Access',
        ],
    ];

    /**
     * @return array{errors: string[], normalized: array<string, mixed>}
     */
    public function validate(array $row): array
    {
        $errors     = [];
        $normalized = [];

        // Coerce known keys; trim strings.
        foreach (array_keys(self::COLUMNS) as $col) {
            $val = $row[$col] ?? null;
            if (is_string($val)) {
                $val = trim($val);
                if ($val === '') $val = null;
            }
            $normalized[$col] = $val;
        }

        // Required scalars
        foreach (['admission_number', 'relationship', 'first_name', 'last_name', 'phone'] as $req) {
            if ($normalized[$req] === null || $normalized[$req] === '') {
                $errors[] = "{$req} is required.";
            }
        }

        // is_primary is required and must parse
        $isPrimary = $this->parseBool($normalized['is_primary'] ?? null);
        if ($normalized['is_primary'] === null) {
            $errors[] = 'is_primary is required.';
        } elseif ($isPrimary === null) {
            $errors[] = 'is_primary must be yes/no or true/false.';
        }
        $normalized['is_primary'] = $isPrimary ?? false;

        // Relationship enum
        if ($normalized['relationship'] !== null) {
            $rel = strtolower((string) $normalized['relationship']);
            if (!in_array($rel, GuardianRelationshipEnum::values(), true)) {
                $errors[] = "relationship must be one of: " . implode(', ', GuardianRelationshipEnum::values()) . '.';
            }
            $normalized['relationship'] = $rel;
        }

        // Phone normalization (required)
        $phoneRaw = $normalized['phone'];
        $phone    = PhoneNormalizer::normalize($phoneRaw !== null ? (string) $phoneRaw : null);
        if ($phoneRaw !== null && $phone === null) {
            $errors[] = 'phone is not a valid phone number.';
        }
        $normalized['phone'] = $phone;

        // whatsapp_number / emergency_contact (optional)
        foreach (['whatsapp_number', 'emergency_contact'] as $phoneField) {
            if ($normalized[$phoneField] !== null) {
                $n = PhoneNormalizer::normalize((string) $normalized[$phoneField]);
                if ($n === null) {
                    $errors[] = "{$phoneField} is not a valid phone number.";
                }
                $normalized[$phoneField] = $n;
            }
        }

        // Email (optional, lowercase)
        if ($normalized['email'] !== null) {
            $email = Str::lower((string) $normalized['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'email is not a valid email address.';
                $normalized['email'] = null;
            } else {
                $normalized['email'] = $email;
            }
        }

        // Gender (optional, normalized)
        if ($normalized['gender'] !== null) {
            $g = GenderTypeEnum::normalizeGender((string) $normalized['gender']);
            if ($g === '') {
                $errors[] = 'gender must be male, female, or other.';
                $normalized['gender'] = null;
            } else {
                $normalized['gender'] = $g;
            }
        }

        // Marital status (optional, enum)
        if ($normalized['marital_status'] !== null) {
            $ms = strtolower((string) $normalized['marital_status']);
            if (!in_array($ms, MaritalStatusEnum::values(), true)) {
                $errors[] = 'marital_status must be one of: ' . implode(', ', MaritalStatusEnum::values()) . '.';
                $normalized['marital_status'] = null;
            } else {
                $normalized['marital_status'] = $ms;
            }
        }

        // ID type / number coupling
        $idType   = $normalized['id_type'];
        $idNumber = $normalized['id_number'];
        if ($idType !== null) {
            $t = strtolower((string) $idType);
            if (!in_array($t, GuardianIdTypeEnum::values(), true)) {
                $errors[] = 'id_type must be one of: ' . implode(', ', GuardianIdTypeEnum::values()) . '.';
                $normalized['id_type'] = null;
            } else {
                $normalized['id_type'] = $t;
            }
        }
        if (($idType !== null) !== ($idNumber !== null)) {
            $errors[] = 'id_type and id_number must be provided together.';
        }

        // id_expiry_date — only accept ISO YYYY-MM-DD (or Excel serial via normalizeDate).
        if ($normalized['id_expiry_date'] !== null) {
            $raw = $normalized['id_expiry_date'];
            $iso = $this->parseStrictDate($raw);
            if ($iso === null) {
                $errors[] = 'id_expiry_date must be in YYYY-MM-DD format.';
                $normalized['id_expiry_date'] = null;
            } else {
                $normalized['id_expiry_date'] = $iso;
            }
        }

        // Status (optional, enum)
        if ($normalized['status'] !== null) {
            $s = strtolower((string) $normalized['status']);
            if (!in_array($s, GuardianStatusEnum::values(), true)) {
                $errors[] = 'status must be one of: ' . implode(', ', GuardianStatusEnum::values()) . '.';
                $normalized['status'] = GuardianStatusEnum::ACTIVE->value;
            } else {
                $normalized['status'] = $s;
            }
        } else {
            $normalized['status'] = GuardianStatusEnum::ACTIVE->value;
        }

        // can_login (optional boolean, default false)
        $canLogin = $this->parseBool($normalized['can_login'] ?? null);
        if ($normalized['can_login'] !== null && $canLogin === null) {
            $errors[] = 'can_login must be yes/no or true/false.';
        }
        $normalized['can_login'] = $canLogin ?? false;

        // Preferred contact channel (optional, enum; default email if email present, else sms).
        if ($normalized['preferred_contact_channel'] !== null) {
            $ch = strtolower((string) $normalized['preferred_contact_channel']);
            if (!in_array($ch, PreferredContactChannelEnum::values(), true)) {
                $errors[] = 'preferred_contact_channel must be one of: ' . implode(', ', PreferredContactChannelEnum::values()) . '.';
                $normalized['preferred_contact_channel'] = null;
            } else {
                $normalized['preferred_contact_channel'] = $ch;
            }
        }
        if ($normalized['preferred_contact_channel'] === null) {
            $normalized['preferred_contact_channel'] = $normalized['email']
                ? PreferredContactChannelEnum::EMAIL->value
                : PreferredContactChannelEnum::SMS->value;
        }

        return ['errors' => $errors, 'normalized' => $normalized];
    }

    private function parseBool(mixed $value): ?bool
    {
        if ($value === null) return null;
        if (is_bool($value)) return $value;

        $v = strtolower(trim((string) $value));
        return match ($v) {
            '1', 'true', 'yes', 'y'  => true,
            '0', 'false', 'no', 'n', '' => false,
            default => null,
        };
    }

    /**
     * Strict YYYY-MM-DD (or Excel serial). Returns ISO date or null.
     */
    private function parseStrictDate(mixed $value): ?string
    {
        if (is_numeric($value)) {
            return normalizeDate($value);
        }

        $raw = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
