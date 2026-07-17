<?php

namespace Database\Factories;

use App\Models\Curriculum;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Curriculum>
 *
 * Minimal by design: the only NOT-NULL / no-default column is uuid (set by the
 * AddUuid concern), and term_id / class_level_arm_id / exam_type_id are all
 * nullable, so a valid Curriculum needs only a school. min_subjects / status /
 * is_ccm fall back to their column defaults (1 / 'draft' / false). States are
 * provided for the shapes tests actually need.
 */
class CurriculumFactory extends Factory
{
    protected $model = Curriculum::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'status' => 'active',
            'is_ccm' => false,
            'min_subjects' => 1,
        ];
    }

    public function ccm(): static
    {
        return $this->state(fn () => ['is_ccm' => true]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }
}
