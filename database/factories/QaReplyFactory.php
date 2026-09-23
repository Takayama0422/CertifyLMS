<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaReply>
 */
class QaReplyFactory extends Factory
{
    protected $model = QaReply::class;

    public function definition(): array
    {
        return [
            'qa_thread_id' => QaThread::factory(),
            'user_id' => User::factory()->student(),
            'body' => fake()->paragraph(),
        ];
    }
}
