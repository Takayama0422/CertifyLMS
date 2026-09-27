<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `GET /ai-chat` の挙動を検証する。
 *
 * - ロール別可否: 学習中の受講生のみ通過
 * - 会話 0 件は empty-state、1 件以上は最新会話へ redirect
 * - 機能スイッチ OFF は 404
 * - API キー未設定時は利用不可の案内(flash warning)
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
    }

    public function test_coach_forbidden(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $this->actingAs($coach)->get(route('ai-chat.index'))->assertForbidden();
    }

    public function test_admin_forbidden(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $this->actingAs($admin)->get(route('ai-chat.index'))->assertForbidden();
    }

    public function test_graduated_student_forbidden(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $this->actingAs($student)->get(route('ai-chat.index'))->assertForbidden();
    }

    public function test_empty_state_when_no_conversations(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)->get(route('ai-chat.index'));

        $response->assertOk();
        $response->assertViewIs('ai-chat.empty-state');
    }

    public function test_redirects_to_latest_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $older = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'last_message_at' => Carbon::now()->subHours(3),
        ]);
        $newer = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'last_message_at' => Carbon::now()->subMinutes(5),
        ]);

        $response = $this->actingAs($student)->get(route('ai-chat.index'));

        $response->assertRedirect(route('ai-chat.conversations.show', $newer));
        $this->assertNotSame($older->id, $newer->id);
    }

    public function test_other_students_conversations_are_not_visible(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        AiChatConversation::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($student)->get(route('ai-chat.index'));

        $response->assertOk();
        $response->assertViewIs('ai-chat.empty-state');
    }

    public function test_disabled_feature_returns_404(): void
    {
        config(['ai-chat.enabled' => false]);
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)->get(route('ai-chat.index'))->assertNotFound();
    }

    public function test_flashes_warning_when_api_key_missing(): void
    {
        config(['ai-chat.gemini.api_key' => null]);
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)->get(route('ai-chat.index'));

        $response->assertOk();
        $response->assertSessionHas('warning');
    }

    public function test_no_warning_flashed_when_api_key_present(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)->get(route('ai-chat.index'));

        $response->assertOk();
        $response->assertSessionMissing('warning');
    }
}
