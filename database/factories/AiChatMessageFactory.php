<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatMessage>
 */
class AiChatMessageFactory extends Factory
{
    protected $model = AiChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_chat_conversation_id' => AiChatConversation::factory(),
            'user_id' => User::factory()->student()->inProgress(),
            'role' => AiChatMessageRole::User->value,
            'status' => AiChatMessageStatus::Completed->value,
            'content' => fake()->realText(80),
            'error_detail' => null,
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'response_time_ms' => null,
        ];
    }

    public function fromUser(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::User->value,
            'status' => AiChatMessageStatus::Completed->value,
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'response_time_ms' => null,
        ]);
    }

    public function fromAssistant(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Completed->value,
            'model' => 'gemini-2.5-flash',
            'input_tokens' => fake()->numberBetween(50, 400),
            'output_tokens' => fake()->numberBetween(20, 300),
            'response_time_ms' => fake()->numberBetween(400, 3000),
        ]);
    }

    public function assistantError(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Error->value,
            'content' => '',
            'error_detail' => 'Gemini API error (HTTP 503): The model is overloaded.',
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'response_time_ms' => null,
        ]);
    }
}
