<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `DELETE /ai-chat/conversations/{conversation}` の挙動を検証する。
 *
 * - 会話オーナーのみ削除可
 * - 削除するとメッセージも連動して削除される(cascade)
 */
class DestroyConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
    }

    public function test_owner_can_delete_conversation_and_messages_cascade(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $message = AiChatMessage::factory()->fromUser()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
        ]);

        $response = $this->actingAs($student)->delete(route('ai-chat.conversations.destroy', $conversation));

        $response->assertRedirect(route('ai-chat.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('ai_chat_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('ai_chat_messages', ['id' => $message->id]);
    }

    public function test_other_student_forbidden(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $stranger = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertForbidden();

        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $conversation->id]);
    }

    public function test_graduated_owner_forbidden(): void
    {
        // 受講中に作った会話でも、修了(卒業)後は直リンクの削除が通ってはならない。
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);
        $owner->update(['status' => UserStatus::Graduated->value]);

        $this->actingAs($owner->fresh())
            ->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertForbidden();

        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $conversation->id]);
    }
}
