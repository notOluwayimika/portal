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
        public readonly \Carbon\Carbon|string|null $date_of_birth,
        public readonly ?string $admission_number,
        public readonly ?int $photo_id,
        public readonly ?int $curriculum_id = null,
        public readonly ?int $promoted_to_id = null,
        public readonly \Carbon\Carbon|string|null $admission_date = null,
        public readonly ?string $address = null,
        public readonly ?string $nationality = null,
        public readonly ?string $other_nationality = null,
        public readonly ?string $state_of_origin = null,
        public readonly ?string $religion = null,
        public readonly ?string $previous_school = null,
        public readonly ?int $sport_house_id = null,
        public readonly ?int $scholarship_id = null,
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
            admission_number: $data['admission_number'] ?? null,
            photo_id: $data['photo_id'] ?? null,
            curriculum_id: $data['curriculum_id'] ?? null,
            promoted_to_id: $data['promoted_to_id'] ?? null,
            admission_date: $data['admission_date'] ?? null,
            address: $data['address'] ?? null,
            nationality: $data['nationality'] ?? null,
            other_nationality: $data['other_nationality'] ?? null,
            state_of_origin: $data['state_of_origin'] ?? null,
            religion: $data['religion'] ?? null,
            previous_school: $data['previous_school'] ?? null,
            sport_house_id: $data['sport_house_id'] ?? null,
            scholarship_id: $data['scholarship_id'] ?? null,
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
