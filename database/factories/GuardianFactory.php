<?php

namespace Database\Factories;

use App\Enums\GuardianIdTypeEnum;
use App\Enums\GuardianStatusEnum;
use App\Enums\MaritalStatusEnum;
use App\Models\Guardian;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guardian>
 */
class GuardianFactory extends Factory
{
    protected $model = Guardian::class;

    public function definition(): array
    {
        return [
            'school_id'         => School::factory(),
            'user_id'           => User::factory(),
            'first_name'        => fake()->firstName(),
            'middle_name'       => fake()->optional()->firstName(),
            'last_name'         => fake()->lastName(),
            'gender'            => fake()->randomElement(['male', 'female', 'other']),
            'phone'             => fake()->phoneNumber(),
            'whatsapp_number'   => fake()->optional()->phoneNumber(),
            'city'              => fake()->city(),
            'state'             => fake()->state(),
            'country'           => fake()->country(),
            'postal_code'       => fake()->postcode(),
            'occupation'        => fake()->jobTitle(),
            'employer_name'     => fake()->company(),
            'marital_status'    => fake()->randomElement(MaritalStatusEnum::values()),
            'emergency_contact' => fake()->phoneNumber(),
            'photo_id'          => null,
            'id_type'           => fake()->randomElement(GuardianIdTypeEnum::values()),
            'id_number'         => fake()->bothify('??######'),
            'id_expiry_date'    => fake()->dateTimeBetween('+1 year', '+10 years')->format('Y-m-d'),
            'status'            => GuardianStatusEnum::ACTIVE->value,
        ];
    }
}
