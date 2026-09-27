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
 * `GET /ai-chat/conversations/{conversation}` の挙動を検証する。
 *
 * - 会話オーナーのみ閲覧可(他の受講生は 403)
 * - HTML(フル画面表示)と JSON(ウィジェットの履歴復元)の 2 経路
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
    }

    public function test_owner_can_view_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::factory()->fromUser()->create(['ai_chat_conversation_id' => $conversation->id, 'user_id' => $student->id]);

        $response = $this->actingAs($student)->get(route('ai-chat.conversations.show', $conversation));

        $response->assertOk();
        $response->assertViewIs('ai-chat.show');
    }

    public function test_other_student_forbidden(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $stranger = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->get(route('ai-chat.conversations.show', $conversation))
            ->assertForbidden();
    }

    public function test_graduated_owner_forbidden(): void
    {
        // 受講中に作った会話でも、修了(卒業)後は直リンクの詳細閲覧が通ってはならない。
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);
        $owner->update(['status' => UserStatus::Graduated->value]);

        $this->actingAs($owner->fresh())
            ->get(route('ai-chat.conversations.show', $conversation))
            ->assertForbidden();
    }

    public function test_json_request_returns_messages(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $userMessage = AiChatMessage::factory()->fromUser()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'content' => 'こんにちは',
        ]);
        $assistantMessage = AiChatMessage::factory()->fromAssistant()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'content' => 'こんにちは、ご質問をどうぞ。',
        ]);

        $response = $this->actingAs($student)->getJson(route('ai-chat.conversations.show', $conversation));

        $response->assertOk();
        $response->assertJsonPath('messages.0.id', $userMessage->id);
        $response->assertJsonPath('messages.0.role', 'user');
        $response->assertJsonPath('messages.1.id', $assistantMessage->id);
        $response->assertJsonPath('messages.1.role', 'assistant');
    }

    public function test_json_request_from_stranger_returns_403(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $stranger = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->getJson(route('ai-chat.conversations.show', $conversation))
            ->assertForbidden();
    }

    public function test_flashes_warning_when_api_key_missing(): void
    {
        config(['ai-chat.gemini.api_key' => null]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->get(route('ai-chat.conversations.show', $conversation));

        $response->assertOk();
        $response->assertSessionHas('warning');
    }
}
