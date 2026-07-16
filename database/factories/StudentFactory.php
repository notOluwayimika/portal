<?php

namespace Database\Factories;

use App\Enums\StudentMembershipStatus;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * The uuid is set by the AddUuid concern. admission_number is set
     * explicitly here (deterministic + unique) rather than relying on the
     * racy HasAdmissionNumber hook.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'middle_name' => fake()->optional()->firstName(),
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'date_of_birth' => fake()->dateTimeBetween('-18 years', '-5 years')->format('Y-m-d'),
            'admission_number' => 'ADM'.fake()->unique()->numerify('#####'),
            'status' => StudentMembershipStatus::ACTIVE->value,
        ];
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => [
            'status' => StudentMembershipStatus::WITHDRAWN->value,
            'left_at' => now(),
            'leave_reason' => 'Relocated',
        ]);
    }
}
