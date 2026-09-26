<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `config('ai-chat.enabled')` が false のとき、AI 相談の全ルートが 404 になることを一括で検証する。
 * (画面・経路ごと利用不可 — 個々の機能テストとは別に、スイッチそのものの効力を横断確認する)
 */
class FeatureToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_ai_chat_routes_are_404_when_disabled(): void
    {
        config(['ai-chat.enabled' => false, 'ai-chat.gemini.api_key' => 'test-key']);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)->get(route('ai-chat.index'))->assertNotFound();
        $this->actingAs($student)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget'])->assertNotFound();
        $this->actingAs($student)->get(route('ai-chat.conversations.show', $conversation))->assertNotFound();
        $this->actingAs($student)->patch(route('ai-chat.conversations.update', $conversation), ['title' => 'x'])->assertNotFound();
        $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => 'x'],
        )->assertNotFound();
        $this->actingAs($student)->delete(route('ai-chat.conversations.destroy', $conversation))->assertNotFound();
    }

    public function test_all_ai_chat_routes_work_when_enabled(): void
    {
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);

        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)->get(route('ai-chat.index'))->assertOk();
    }
}
