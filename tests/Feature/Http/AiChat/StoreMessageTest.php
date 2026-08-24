<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `POST /ai-chat/conversations/{conversation}/messages` の挙動を検証する。
 *
 * 外部通信(Gemini API)はすべて `Http::fake()` でモックし、実通信は発生させない。
 *
 * - 正常系(200 + user_message / assistant_message / conversation)
 * - 通信エラー / 空応答 → 502(受講生のメッセージ自体は保存され、同じ内容を送り直せる)
 * - 一時的なエラー(503)からの再試行 → 最終的に成功
 * - 1 日の送信上限超過 → 429(何も保存しない)
 * - API キー未設定 → 502(実通信は発生しない)
 * - 入力検証(本文必須 / 最大 2000)
 * - 会話オーナー以外は 403
 */
class StoreMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai-chat.enabled' => true,
            'ai-chat.gemini.api_key' => 'test-key',
            'ai-chat.gemini.retry_delay_ms' => 0,
            'ai-chat.daily_message_limit' => 30,
        ]);
    }

    public function test_successful_reply_is_persisted_and_returned(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response($this->successBody('二分探索木は…'), 200),
        ]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '二分探索木について教えてください。'],
        );

        $response->assertOk();
        $response->assertJsonPath('user_message.role', 'user');
        $response->assertJsonPath('assistant_message.role', 'assistant');
        $response->assertJsonPath('assistant_message.status', 'completed');
        $response->assertJsonPath('assistant_message.content', '二分探索木は…');

        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => '二分探索木について教えてください。',
        ]);
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Completed->value,
            'model' => 'gemini-2.5-flash',
        ]);
    }

    public function test_request_structure_includes_history_and_context(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response($this->successBody('OK'), 200),
        ]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::factory()->fromUser()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'content' => '前回の質問',
        ]);
        AiChatMessage::factory()->fromAssistant()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'content' => '前回の回答',
        ]);

        $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '今回の質問'],
        );

        Http::assertSent(function ($request) {
            $contents = $request->data()['contents'] ?? [];

            $texts = array_map(fn ($c) => $c['parts'][0]['text'], $contents);

            return in_array('前回の質問', $texts, true)
                && in_array('前回の回答', $texts, true)
                && in_array('今回の質問', $texts, true);
        });
    }

    public function test_upstream_failure_persists_user_message_and_returns_502(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(['error' => ['message' => 'overloaded']], 503),
        ]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '質問です'],
        );

        $response->assertStatus(502);
        $response->assertJsonStructure(['message', 'upstream_status']);

        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
            'content' => '質問です',
        ]);
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Error->value,
        ]);
    }

    public function test_empty_response_is_treated_as_failure(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '']]]]]], 200),
        ]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '質問です'],
        );

        $response->assertStatus(502);
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Error->value,
        ]);
    }

    public function test_retries_transient_error_then_succeeds(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push($this->successBody('リトライ後に成功'), 200),
        ]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '質問です'],
        );

        $response->assertOk();
        $response->assertJsonPath('assistant_message.content', 'リトライ後に成功');
        Http::assertSentCount(2);
    }

    public function test_can_resend_same_content_after_failure(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::sequence()
                ->push(['error' => ['message' => 'boom']], 500)
                ->push(['error' => ['message' => 'boom']], 500)
                ->push(['error' => ['message' => 'boom']], 500)
                ->push($this->successBody('2 回目で成功'), 200),
        ]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $first = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '同じ質問'],
        );
        $first->assertStatus(502);

        $second = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '同じ質問'],
        );
        $second->assertOk();

        $this->assertSame(2, AiChatMessage::query()
            ->where('ai_chat_conversation_id', $conversation->id)
            ->where('role', AiChatMessageRole::User->value)
            ->count());
    }

    public function test_daily_limit_exceeded_returns_429_and_persists_nothing(): void
    {
        config(['ai-chat.daily_message_limit' => 2]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        for ($i = 0; $i < 2; $i++) {
            AiChatMessage::factory()->fromUser()->create([
                'ai_chat_conversation_id' => $conversation->id,
                'user_id' => $student->id,
            ]);
        }

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '上限超過です'],
        );

        $response->assertStatus(429);
        $this->assertDatabaseMissing('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'content' => '上限超過です',
        ]);
    }

    public function test_daily_limit_counts_across_conversations(): void
    {
        config(['ai-chat.daily_message_limit' => 1]);

        $student = User::factory()->student()->inProgress()->create();
        $otherConversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::factory()->fromUser()->create([
            'ai_chat_conversation_id' => $otherConversation->id,
            'user_id' => $student->id,
        ]);

        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '別会話でも上限に達している'],
        );

        $response->assertStatus(429);
    }

    public function test_api_key_missing_returns_502_without_real_request(): void
    {
        config(['ai-chat.gemini.api_key' => null]);
        Http::fake();

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '質問です'],
        );

        $response->assertStatus(502);
        Http::assertNothingSent();

        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::User->value,
        ]);
    }

    public function test_content_required(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => ''],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('content');
    }

    public function test_content_max_length(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => str_repeat('a', 2001)],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('content');
    }

    public function test_content_at_max_length_is_accepted(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response($this->successBody('OK'), 200),
        ]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => str_repeat('a', 2000)],
        );

        $response->assertOk();
    }

    public function test_non_owner_forbidden(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $stranger = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($stranger)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '不正アクセス'],
        )->assertForbidden();
    }

    public function test_disabled_feature_returns_404(): void
    {
        config(['ai-chat.enabled' => false]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '質問'],
        )->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function successBody(string $text): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => $text]]]],
            ],
            'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 25],
        ];
    }
}
