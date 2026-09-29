<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AiChatMessage モデルのリレーション・Cast を検証する Unit テスト。
 *
 * - 2 リレーション(conversation / user)
 * - cast(role / status enum, input_tokens / output_tokens / response_time_ms integer)
 */
class AiChatMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_relation_returns_owning_conversation(): void
    {
        $conversation = AiChatConversation::factory()->create();
        $message = AiChatMessage::factory()->fromUser()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
        ]);

        $this->assertTrue($message->conversation->is($conversation));
    }

    public function test_user_relation_returns_sender(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $message = AiChatMessage::factory()->fromUser()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
        ]);

        $this->assertTrue($message->user->is($student));
    }

    public function test_role_and_status_casts(): void
    {
        $message = AiChatMessage::factory()->fromAssistant()->create();

        $fresh = $message->fresh();

        $this->assertInstanceOf(AiChatMessageRole::class, $fresh->role);
        $this->assertSame(AiChatMessageRole::Assistant, $fresh->role);
        $this->assertInstanceOf(AiChatMessageStatus::class, $fresh->status);
        $this->assertSame(AiChatMessageStatus::Completed, $fresh->status);
    }

    public function test_numeric_metadata_casts(): void
    {
        $message = AiChatMessage::factory()->fromAssistant()->create([
            'input_tokens' => '120',
            'output_tokens' => '45',
            'response_time_ms' => '900',
        ]);

        $fresh = $message->fresh();

        $this->assertIsInt($fresh->input_tokens);
        $this->assertIsInt($fresh->output_tokens);
        $this->assertIsInt($fresh->response_time_ms);
    }
}
