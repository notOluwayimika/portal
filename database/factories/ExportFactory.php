<?php

namespace Database\Factories;

use App\Models\Export;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    public function definition(): array
    {
        $uuid = (string) Str::orderedUuid();

        return [
            'school_id' => School::factory(),
            'user_id' => User::factory(),
            'type' => 'activity_log',
            'disk' => 'local',
            'file_name' => 'activity-log-'.fake()->numerify('########').'.csv',
            'file_path' => "exports/1/1/{$uuid}.csv",
            'row_count' => fake()->numberBetween(0, 5000),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
