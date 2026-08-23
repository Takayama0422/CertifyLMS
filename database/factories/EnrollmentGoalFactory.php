<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentGoal>
 */
class EnrollmentGoalFactory extends Factory
{
    protected $model = EnrollmentGoal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->optional()->paragraph(),
            'target_date' => now()->addMonth()->toDateString(),
            'achieved_at' => null,
        ];
    }

    public function achieved(): static
    {
        return $this->state(fn () => [
            'achieved_at' => now(),
        ]);
    }

    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn () => [
            'enrollment_id' => $enrollment->id,
        ]);
    }
}
