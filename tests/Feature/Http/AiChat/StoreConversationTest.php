<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `POST /ai-chat/conversations` の挙動を検証する。
 *
 * - ウィジェット経由(JSON): 200(既存会話再開)/ 201(新規作成)
 * - フル画面モーダル(HTML form): 会話詳細へ redirect。初回メッセージがあれば同期送信を待つ
 * - 教材(section_id)から始めた会話は同じ教材で乱立しない(dedupe)
 * - 一般相談(section なし)は毎回新規作成
 * - 入力検証(初回メッセージ最大 2000)
 */
class StoreConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
    }

    public function test_widget_creates_general_conversation_and_returns_201(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget']);

        $response->assertStatus(201);
        $response->assertJsonStructure(['conversation' => ['id', 'title']]);
        $this->assertDatabaseHas('ai_chat_conversations', [
            'user_id' => $student->id,
            'section_id' => null,
        ]);
    }

    public function test_widget_repeated_general_requests_create_separate_conversations(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget']);
        $this->actingAs($student)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget']);

        $this->assertSame(2, AiChatConversation::query()->where('user_id', $student->id)->count());
    }

    public function test_widget_reuses_existing_conversation_for_same_section(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $section = $this->createSectionFor($student);

        $first = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id]);
        $first->assertStatus(201);
        $firstId = $first->json('conversation.id');

        $second = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id]);
        $second->assertStatus(200);

        $this->assertSame($firstId, $second->json('conversation.id'));
        $this->assertSame(1, AiChatConversation::query()->where('user_id', $student->id)->count());
    }

    public function test_different_sections_create_separate_conversations(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $sectionA = $this->createSectionFor($student);
        $sectionB = $this->createSectionFor($student);

        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $sectionA->id]);
        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $sectionB->id]);

        $this->assertSame(2, AiChatConversation::query()->where('user_id', $student->id)->count());
    }

    public function test_full_screen_form_without_message_redirects_to_show(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)
            ->post(route('ai-chat.conversations.store'), ['source' => 'full-screen']);

        $conversation = AiChatConversation::query()->where('user_id', $student->id)->firstOrFail();
        $response->assertRedirect(route('ai-chat.conversations.show', $conversation));
        $this->assertSame(0, AiChatMessage::query()->where('ai_chat_conversation_id', $conversation->id)->count());
    }

    public function test_full_screen_form_with_initial_message_sends_synchronously(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response($this->geminiSuccessBody('こんにちは、お手伝いします。'), 200),
        ]);

        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)->post(route('ai-chat.conversations.store'), [
            'source' => 'full-screen',
            'message' => '二分探索木について教えてください。',
        ]);

        $conversation = AiChatConversation::query()->where('user_id', $student->id)->firstOrFail();
        $response->assertRedirect(route('ai-chat.conversations.show', $conversation));

        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => '二分探索木について教えてください。',
        ]);
        $this->assertDatabaseHas('ai_chat_messages', [
            'ai_chat_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'status' => 'completed',
        ]);
    }

    public function test_initial_message_max_length_validation(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)->post(route('ai-chat.conversations.store'), [
            'source' => 'full-screen',
            'message' => str_repeat('a', 2001),
        ]);

        $response->assertSessionHasErrors('message');
    }

    public function test_section_not_enrolled_by_student_is_rejected(): void
    {
        // 受講していない資格の教材の ID を送っても会話を作れてはならない(レビュー指摘 2)。
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->for($certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->published()->create();
        // Enrollment を作らない = 受講していない。

        $response = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('section_id');
        $this->assertDatabaseMissing('ai_chat_conversations', ['section_id' => $section->id]);
    }

    public function test_draft_section_is_rejected(): void
    {
        // 下書きの教材の ID を送っても会話を作れてはならない(レビュー指摘 2)。
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->for($certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->draft()->create();

        Enrollment::factory()->for($student)->for($certification)->create();

        $response = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('section_id');
    }

    public function test_section_under_draft_chapter_is_rejected(): void
    {
        // Section 自体は公開でも、親(Chapter / Part)が下書きなら非公開扱い(cascade)。
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->for($certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->draft()->create();
        $section = Section::factory()->for($chapter)->published()->create();

        Enrollment::factory()->for($student)->for($certification)->create();

        $response = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('section_id');
    }

    public function test_nonexistent_section_is_rejected(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => 'nonexistent-id']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('section_id');
    }

    public function test_non_student_forbidden(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $this->actingAs($coach)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget'])
            ->assertForbidden();
    }

    public function test_disabled_feature_returns_404(): void
    {
        config(['ai-chat.enabled' => false]);
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget'])
            ->assertNotFound();
    }

    private function createSectionFor(User $student): Section
    {
        // 「受講中の資格の、公開されている教材のみ受け付ける」検証(レビュー指摘 2)を満たすため、
        // Part / Chapter / Section をすべて公開状態にし、受講中(learning)の Enrollment も用意する。
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->for($certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->published()->create();

        Enrollment::factory()->for($student)->for($certification)->create();

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiSuccessBody(string $text): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => $text]]]],
            ],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 30],
        ];
    }
}
