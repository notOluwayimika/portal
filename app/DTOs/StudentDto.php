<?php

namespace App\DTOs;

use Carbon\Carbon;

readonly class StudentDto
{
    public function __construct(
        public readonly ?int $school_id,
        public readonly ?int $user_id,
        public readonly string $first_name,
        public readonly string $last_name,
        public readonly ?string $middle_name,
        public readonly string $gender,
        public readonly Carbon|string|null $date_of_birth,
        public readonly string $admission_number,
        public readonly ?string $photo,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            school_id: $data['school_id'],
            user_id: $data['user_id'] ?? null,
            first_name: $data['first_name'],
            last_name: $data['last_name'],
            middle_name: $data['middle_name'] ?? null,
            gender: $data['gender'],
            date_of_birth: $data['date_of_birth'] ?? null,
            admission_number: $data['admission_number'],
            photo: $data['photo'] ?? null,
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
