<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatConversation>
 */
class AiChatConversationFactory extends Factory
{
    protected $model = AiChatConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student()->inProgress(),
            'enrollment_id' => null,
            'section_id' => null,
            'title' => fake()->realText(20),
            'title_manually_set' => false,
            'last_message_at' => null,
        ];
    }
}
