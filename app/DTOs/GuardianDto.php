<?php

namespace App\DTOs;

use Carbon\Carbon;

readonly class GuardianDto
{
    public function __construct(
        public readonly ?int    $school_id,
        public readonly ?int    $user_id,
        public readonly string  $first_name,
        public readonly ?string $middle_name,
        public readonly string  $last_name,
        public readonly ?string $gender,
        public readonly string  $phone,
        public readonly ?string $whatsapp_number,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $country,
        public readonly ?string $postal_code,
        public readonly ?string $occupation,
        public readonly ?string $employer_name,
        public readonly ?string $marital_status,
        public readonly ?string $emergency_contact,
        public readonly ?int    $photo_id,
        public readonly ?string $id_type,
        public readonly ?string $id_number,
        public readonly Carbon|string|null $id_expiry_date,
        public readonly ?string $status,
        public readonly ?string $email,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            school_id:         isset($data['school_id']) ? (int) $data['school_id'] : null,
            user_id:           isset($data['user_id']) ? (int) $data['user_id'] : null,
            first_name:        $data['first_name'],
            middle_name:       $data['middle_name']       ?? null,
            last_name:         $data['last_name'],
            gender:            $data['gender']            ?? null,
            phone:             $data['phone'],
            whatsapp_number:   $data['whatsapp_number']   ?? null,
            city:              $data['city']              ?? null,
            state:             $data['state']             ?? null,
            country:           $data['country']           ?? null,
            postal_code:       $data['postal_code']       ?? null,
            occupation:        $data['occupation']        ?? null,
            employer_name:     $data['employer_name']     ?? null,
            marital_status:    $data['marital_status']    ?? null,
            emergency_contact: $data['emergency_contact'] ?? null,
            photo_id:          isset($data['photo_id']) ? (int) $data['photo_id'] : null,
            id_type:           $data['id_type']           ?? null,
            id_number:         $data['id_number']         ?? null,
            id_expiry_date:    $data['id_expiry_date']    ?? null,
            status:            $data['status']            ?? null,
            email:             $data['email']             ?? null,
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function only(string|array ...$keys): array
    {
        $flat = count($keys) === 1 && is_array($keys[0]) ? $keys[0] : $keys;
        return array_intersect_key($this->toArray(), array_flip($flat));
    }

    /**
     * Returns only the guardian table columns (strips email which lives on users).
     */
    public function guardianAttributes(): array
    {
        $data = $this->toArray();
        unset($data['email']);
        return $data;
    }
}
