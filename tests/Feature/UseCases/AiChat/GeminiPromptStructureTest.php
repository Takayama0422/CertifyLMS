<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Enums\AiChatMessageStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\UseCases\AiChat\StoreMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * T-A-04: Gemini へ送る内容(プロンプト構造)の検証。
 *
 * モック手法: Gemini は Laravel の `Http` ファサードで通信するため `Http::fake()` でモックし、
 * 実際に送られたリクエスト本文(`systemInstruction` / `contents` の並びと `role`)を検証する。
 * 既存テストは「履歴の中身が含まれる」「履歴の件数上限」までで、並び順・role・システム指示の組み立ては未検証だった。
 */
#[Group('external')]
#[Group('gemini')]
class GeminiPromptStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai-chat.enabled' => true,
            'ai-chat.gemini.api_key' => 'test-key',
            'ai-chat.auto_title.enabled' => false,
            'ai-chat.system_prompt.base' => 'BASE-PROMPT',
            'ai-chat.system_prompt.section_context' => 'SECTION[:title]',
            'ai-chat.system_prompt.certification_context' => 'CERT[:name]',
        ]);
        Http::fake(['*generativelanguage.googleapis.com*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '回答']]]]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ], 200)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentPayload(): array
    {
        $payloads = [];
        Http::assertSent(function (Request $request) use (&$payloads) {
            $payloads[] = $request->data();

            return true;
        });

        $this->assertCount(1, $payloads, 'Gemini へは 1 回だけ送信される');

        return $payloads[0];
    }

    private function message(AiChatConversation $conversation, User $student, string $role, string $content, ?AiChatMessageStatus $status = null): void
    {
        $factory = AiChatMessage::factory();
        $factory = $role === 'user' ? $factory->fromUser() : $factory->fromAssistant();
        $factory->create(array_filter([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'content' => $content,
            'status' => $status?->value,
        ]));
    }

    public function test_contents_are_sent_oldest_first_with_gemini_roles_and_the_new_message_last(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->message($conversation, $student, 'user', 'Q1');
        $this->message($conversation, $student, 'assistant', 'A1');
        $this->message($conversation, $student, 'user', 'Q2');
        $this->message($conversation, $student, 'assistant', 'A2');

        app(StoreMessageAction::class)($student, $conversation, 'Q3');

        $contents = $this->sentPayload()['contents'];
        $this->assertSame(
            [['user', 'Q1'], ['model', 'A1'], ['user', 'Q2'], ['model', 'A2'], ['user', 'Q3']],
            array_map(fn (array $c): array => [$c['role'], $c['parts'][0]['text']], $contents),
            '古い順に並べ、assistant は Gemini の role である model へ変換し、今回の質問を末尾に置く',
        );
    }

    public function test_only_completed_messages_are_carried_over_as_history(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        $this->message($conversation, $student, 'user', '完了した質問');
        $this->message($conversation, $student, 'assistant', '完了した回答');
        $this->message($conversation, $student, 'user', '失敗した回答の直前の質問');
        $this->message($conversation, $student, 'assistant', '失敗した回答', AiChatMessageStatus::Error);

        app(StoreMessageAction::class)($student, $conversation, '今回');

        $texts = array_map(fn (array $c): string => $c['parts'][0]['text'], $this->sentPayload()['contents']);
        $this->assertNotContains('失敗した回答', $texts, '失敗した応答は AI への入力に引き継がない');
        $this->assertContains('完了した回答', $texts);
        $this->assertSame('今回', end($texts));
    }

    public function test_system_instruction_is_the_base_prompt_alone_for_a_general_conversation(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        app(StoreMessageAction::class)($student, $conversation, '質問');

        $this->assertSame('BASE-PROMPT', $this->sentPayload()['systemInstruction']['parts'][0]['text']);
    }

    public function test_system_instruction_adds_the_section_and_certification_context_in_order(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create(['name' => '基本情報技術者']);
        $section = Section::factory()
            ->for(Chapter::factory()->for(Part::factory()->for($certification)->published())->published())
            ->published()->create(['title' => '2進数の基礎']);
        Enrollment::factory()->for($student)->for($certification)->state(['status' => EnrollmentStatus::Learning->value])->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id, 'section_id' => $section->id]);

        app(StoreMessageAction::class)($student, $conversation, '質問');

        $this->assertSame(
            "BASE-PROMPT\nSECTION[2進数の基礎]\nCERT[基本情報技術者]",
            $this->sentPayload()['systemInstruction']['parts'][0]['text'],
            '基本指示 → 教材の文脈(:title 置換)→ 資格の文脈(:name 置換)の順で改行区切り',
        );
    }

    public function test_the_request_goes_to_the_configured_model_with_the_api_key_header(): void
    {
        config(['ai-chat.gemini.model' => 'gemini-test-model']);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        app(StoreMessageAction::class)($student, $conversation, '質問');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/v1beta/models/gemini-test-model:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-key');
        });
    }
}
