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
            // 1 日あたりの送信上限は user_id + role=user だけで集計するため、既定値が会話の持ち主と
            // 別ユーザーになっていると集計対象から外れたまま通るテストが書けてしまう。
            // ai_chat_conversation_id より後で解決されるため、作成済みの会話の持ち主に揃える。
            'user_id' => fn (array $attributes) => AiChatConversation::query()->find($attributes['ai_chat_conversation_id'])?->user_id
                ?? User::factory()->student()->inProgress(),
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
}
