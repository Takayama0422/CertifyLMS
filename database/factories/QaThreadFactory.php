<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaThread>
 */
class QaThreadFactory extends Factory
{
    protected $model = QaThread::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'certification_id' => Certification::factory()->published(),
            'title' => fake()->sentence(8),
            'body' => fake()->paragraphs(2, true),
            'status' => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'status' => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => QaThreadStatus::Resolved->value,
            'resolved_at' => now(),
        ]);
    }
}
