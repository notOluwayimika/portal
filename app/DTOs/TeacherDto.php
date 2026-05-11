<?php

namespace App\DTOs;

use Carbon\Carbon;

readonly class TeacherDto
{
    public function __construct(
        public readonly ?int    $school_id,
        public readonly ?int    $user_id,
        public readonly string  $first_name,
        public readonly string  $last_name,
        public readonly ?string $staff_number,
        public readonly ?string $gender,
        public readonly Carbon|string|null $date_of_birth,
        public readonly ?string $phone,
        public readonly ?string $address,
        public readonly ?string $qualification,
        public readonly Carbon|string|null $hire_date,
        public readonly ?string $status,
        public readonly ?int    $photo_id,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            school_id:     $data['school_id']     ?? null,
            user_id:       $data['user_id']       ?? null,
            first_name:    $data['first_name'],
            last_name:     $data['last_name'],
            staff_number:  $data['staff_number']  ?? null,
            gender:        $data['gender']        ?? null,
            date_of_birth: $data['date_of_birth'] ?? null,
            phone:         $data['phone']         ?? null,
            address:       $data['address']       ?? null,
            qualification: $data['qualification'] ?? null,
            hire_date:     $data['hire_date']     ?? null,
            status:        $data['status']        ?? null,
            photo_id:      $data['photo_id']      ?? null,
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
