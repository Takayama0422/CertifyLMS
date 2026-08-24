<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `PATCH /ai-chat/conversations/{conversation}` (タイトル手動編集)の挙動を検証する。
 *
 * - 会話オーナーのみ
 * - タイトル必須 / 最大 100 文字
 * - 手動編集後は `title_manually_set = true` になり、以後 AI の自動改題対象から外れる
 */
class UpdateConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
    }

    public function test_owner_can_rename(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id, 'title' => '元のタイトル']);

        $response = $this->actingAs($student)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => '新しいタイトル']);

        $response->assertRedirect(route('ai-chat.conversations.show', $conversation));
        $response->assertSessionHas('success');
        $conversation->refresh();
        $this->assertSame('新しいタイトル', $conversation->title);
        $this->assertTrue($conversation->title_manually_set);
    }

    public function test_other_student_forbidden(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $stranger = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => '乗っ取り'])
            ->assertForbidden();
    }

    public function test_title_required(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => '']);

        $response->assertSessionHasErrors('title');
    }

    public function test_title_max_length(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => str_repeat('a', 101)]);

        $response->assertSessionHasErrors('title');
    }

    public function test_title_at_max_length_is_accepted(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => str_repeat('a', 100)]);

        $response->assertSessionDoesntHaveErrors('title');
    }
}
