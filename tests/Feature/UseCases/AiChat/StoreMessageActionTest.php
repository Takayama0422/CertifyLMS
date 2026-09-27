<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\UseCases\AiChat\StoreMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * StoreMessageAction の Action 単位テスト。外部通信(Gemini API)はすべて `Http::fake()` でモックし、
 * 実通信は発生させない。
 *
 * - 日次送信上限超過(何も保存しない)
 * - `ai-chat.history_limit` が実際に AI への入力件数を絞っているか(レビュー指摘 7)
 * - 見出しの自動生成(有効時 / 手動編集済みのときのスキップ / 無効化スイッチ / 初回のみ / 失敗しても
 *   本処理は失敗させない)— これまで 1 本もテストが無かった機能(レビュー指摘 5)
 */
class StoreMessageActionTest extends TestCase
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

    public function test_daily_limit_exceeded_throws_and_persists_nothing(): void
    {
        config(['ai-chat.daily_message_limit' => 1]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);
        AiChatMessage::factory()->fromUser()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
        ]);

        $this->expectException(AiChatDailyLimitExceededException::class);

        try {
            app(StoreMessageAction::class)($student, $conversation, '上限超過です');
        } finally {
            $this->assertDatabaseMissing('ai_chat_messages', ['content' => '上限超過です']);
        }
    }

    public function test_history_limit_config_controls_number_of_messages_sent_to_gemini(): void
    {
        config(['ai-chat.auto_title.enabled' => false, 'ai-chat.history_limit' => 2]);
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response($this->replyBody('回答'), 200),
        ]);

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        foreach (['一番古い質問', '二番目の質問', '三番目の質問'] as $content) {
            AiChatMessage::factory()->fromUser()->create([
                'ai_chat_conversation_id' => $conversation->id,
                'user_id' => $student->id,
                'content' => $content,
            ]);
            AiChatMessage::factory()->fromAssistant()->create([
                'ai_chat_conversation_id' => $conversation->id,
                'user_id' => $student->id,
                'content' => $content.'への回答',
            ]);
        }

        app(StoreMessageAction::class)($student, $conversation, '最新の質問');

        Http::assertSent(function ($request) {
            $data = $request->data();
            if (! array_key_exists('systemInstruction', $data)) {
                return false;
            }

            $texts = array_map(fn ($c) => $c['parts'][0]['text'], $data['contents']);

            return in_array('三番目の質問', $texts, true)
                && in_array('三番目の質問への回答', $texts, true)
                && in_array('最新の質問', $texts, true)
                && ! in_array('一番古い質問', $texts, true)
                && ! in_array('二番目の質問', $texts, true)
                && ! in_array('二番目の質問への回答', $texts, true);
        });
    }

    public function test_auto_title_updates_title_after_first_successful_reply(): void
    {
        config(['ai-chat.auto_title.enabled' => true]);
        Http::fake(fn ($request) => $this->fakeResponseFor($request, '二分探索木の基礎'));

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'title' => '新しい相談',
            'title_manually_set' => false,
        ]);

        app(StoreMessageAction::class)($student, $conversation, '質問です');

        $this->assertSame('二分探索木の基礎', $conversation->fresh()->title);
    }

    public function test_auto_title_skipped_when_title_manually_set(): void
    {
        config(['ai-chat.auto_title.enabled' => true]);
        Http::fake(fn ($request) => $this->fakeResponseFor($request, '生成されたタイトル'));

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'title' => '手動タイトル',
            'title_manually_set' => true,
        ]);

        app(StoreMessageAction::class)($student, $conversation, '質問です');

        $this->assertSame('手動タイトル', $conversation->fresh()->title);
        Http::assertSentCount(1); // 改題用の追加リクエストは発生しない
    }

    public function test_auto_title_disabled_via_config(): void
    {
        config(['ai-chat.auto_title.enabled' => false]);
        Http::fake(fn ($request) => $this->fakeResponseFor($request, '生成されたタイトル'));

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'title' => '新しい相談',
            'title_manually_set' => false,
        ]);

        app(StoreMessageAction::class)($student, $conversation, '質問です');

        $this->assertSame('新しい相談', $conversation->fresh()->title);
        Http::assertSentCount(1); // 改題用の追加リクエストは発生しない
    }

    public function test_auto_title_only_triggers_on_first_successful_reply(): void
    {
        config(['ai-chat.auto_title.enabled' => true]);
        Http::fake(fn ($request) => $this->fakeResponseFor($request, '初回改題後タイトル'));

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'title' => '新しい相談',
            'title_manually_set' => false,
        ]);

        app(StoreMessageAction::class)($student, $conversation, '1 回目の質問');
        $titleAfterFirstReply = $conversation->fresh()->title;

        app(StoreMessageAction::class)($student, $conversation->fresh(), '2 回目の質問');

        $this->assertSame('初回改題後タイトル', $titleAfterFirstReply);
        $this->assertSame(
            $titleAfterFirstReply,
            $conversation->fresh()->title,
            '2 回目以降の応答完了では改題が再度走らないはず(毎回変わると混乱するため)',
        );
    }

    public function test_auto_title_generation_failure_does_not_fail_main_reply(): void
    {
        // これまで自動改題には 1 本もテストが無く、かつ再試行系テストが改題ぶんの追加リクエストを
        // 積んでいなかったため、改題側の例外(呼び出し回数不足)が maybeAutoTitle の
        // catch (\Throwable) に握り潰されて気づけない状態だった。ここでは改題側だけを意図的に
        // 失敗させ、それが本処理(応答)の成否やメッセージ本体に影響しないことを明示的に確認する。
        config(['ai-chat.auto_title.enabled' => true]);
        Http::fake(function ($request) {
            $data = $request->data();
            if (! array_key_exists('systemInstruction', $data)) {
                return Http::response(['error' => ['message' => 'title generation boom']], 500);
            }

            return Http::response($this->replyBody('回答本文'), 200);
        });

        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'title' => '新しい相談',
            'title_manually_set' => false,
        ]);

        $result = app(StoreMessageAction::class)($student, $conversation, '質問です');

        $this->assertTrue($result->successful, '改題(補助機能)が失敗しても本処理(応答)は成功のままであるはず');
        $this->assertSame('回答本文', $result->assistantMessage->content);
        $this->assertSame('新しい相談', $conversation->fresh()->title, '改題に失敗した場合はタイトルを変更しない');
    }

    /**
     * `systemInstruction` を含むリクエストは会話応答、含まないリクエストは改題生成として振り分ける。
     */
    private function fakeResponseFor(mixed $request, string $title): mixed
    {
        $data = $request->data();

        if (! array_key_exists('systemInstruction', $data)) {
            return Http::response($this->titleBody($title), 200);
        }

        return Http::response($this->replyBody('回答本文'), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function replyBody(string $text): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => $text]]]],
            ],
            'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 25],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function titleBody(string $title): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => $title]]]],
            ],
        ];
    }
}
