<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\AiChat\GeminiClient;
use Illuminate\Support\Facades\Log;

/**
 * AiChatConversation にメッセージを送信し、Gemini から同期で応答を取得して保存する Action。
 *
 * - 受講生 1 人・1 日あたりの送信回数上限を超えていれば `AiChatDailyLimitExceededException`(429)を
 *   投げる。この場合はメッセージを一切保存しない(超過送信そのものを拒否するため)。
 * - AI 応答が失敗しても受講生のメッセージは既に保存済みなので残る(同じ内容を送り直して再質問できる)。
 *   失敗した assistant 発言も `status = error` として保存する(履歴の一貫性 + 内部記録のため)。
 * - AI への入力には直近 `ai-chat.history_limit` 件の completed メッセージを引き継ぐ。
 * - 初回の assistant 応答が成功した直後、`ai-chat.auto_title.enabled` が true かつ受講生が
 *   まだ手動でタイトル編集していなければ、AI に短いタイトルを生成させて会話タイトルを更新する
 *   (失敗しても本処理全体は失敗させない)。
 */
final class StoreMessageAction
{
    public function __construct(private readonly GeminiClient $gemini) {}

    public function __invoke(User $user, AiChatConversation $conversation, string $content): AiChatMessageResult
    {
        $this->assertWithinDailyLimit($user);

        $userMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiChatMessageRole::User->value,
            'status' => AiChatMessageStatus::Completed->value,
            'content' => $content,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        $result = $this->gemini->generateReply(
            $this->buildSystemInstruction($conversation),
            $this->buildHistory($conversation, $userMessage),
            $content,
        );

        $assistantMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => $result->successful ? AiChatMessageStatus::Completed->value : AiChatMessageStatus::Error->value,
            'content' => $result->successful ? (string) $result->content : '',
            'error_detail' => $result->successful ? null : $result->describeError(),
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'response_time_ms' => $result->responseTimeMs,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        if ($result->successful) {
            Log::channel('ai-chat')->info('gemini reply succeeded', [
                'conversation_id' => $conversation->id,
                'model' => $result->model,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'response_time_ms' => $result->responseTimeMs,
            ]);

            $this->maybeAutoTitle($conversation, $userMessage, $assistantMessage);
        } else {
            Log::channel('ai-chat')->warning('gemini reply failed', [
                'conversation_id' => $conversation->id,
                'upstream_status' => $result->upstreamStatus,
                'error_detail' => $result->errorDetail,
                'response_time_ms' => $result->responseTimeMs,
            ]);
        }

        return new AiChatMessageResult(
            userMessage: $userMessage,
            assistantMessage: $assistantMessage,
            successful: $result->successful,
            upstreamStatus: $result->upstreamStatus,
        );
    }

    /**
     * @throws AiChatDailyLimitExceededException
     */
    private function assertWithinDailyLimit(User $user): void
    {
        $limit = (int) config('ai-chat.daily_message_limit', 30);

        $sentToday = AiChatMessage::query()
            ->where('user_id', $user->id)
            ->where('role', AiChatMessageRole::User->value)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($sentToday >= $limit) {
            throw new AiChatDailyLimitExceededException;
        }
    }

    /**
     * @return array<int, array{role: 'user'|'assistant', content: string}>
     */
    private function buildHistory(AiChatConversation $conversation, AiChatMessage $excludeMessage): array
    {
        $limit = (int) config('ai-chat.history_limit', 20);

        return AiChatMessage::query()
            ->where('ai_chat_conversation_id', $conversation->id)
            ->where('id', '!=', $excludeMessage->id)
            ->where('status', AiChatMessageStatus::Completed->value)
            // created_at は 1 リクエスト内の連続保存で同一秒になるのが常態のため、
            // id(ULID, ミリ秒精度 + 単調増加)を secondary sort として発言順を確定させる。
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn (AiChatMessage $m) => [
                'role' => $m->role === AiChatMessageRole::User ? 'user' : 'assistant',
                'content' => $m->content,
            ])
            ->values()
            ->all();
    }

    /**
     * システムプロンプトは `config('ai-chat.system_prompt.*')`(実体は環境変数)から読む。
     * 管理画面 / DB 管理は仕様のスコープ外(「環境設定で完結」)のため、固定文字列をコードに
     * 直書きせず設定値化している。`:title` / `:name` はここで動的な値へ置換するプレースホルダ。
     */
    private function buildSystemInstruction(AiChatConversation $conversation): string
    {
        $conversation->loadMissing(['section.chapter.part.certification', 'enrollment.certification']);

        $lines = [(string) config('ai-chat.system_prompt.base')];

        if ($conversation->section !== null) {
            $lines[] = str_replace(
                ':title',
                $conversation->section->title,
                (string) config('ai-chat.system_prompt.section_context'),
            );
        }

        $certificationName = $conversation->enrollment?->certification?->name
            ?? $conversation->section?->chapter?->part?->certification?->name;

        if ($certificationName !== null) {
            $lines[] = str_replace(
                ':name',
                $certificationName,
                (string) config('ai-chat.system_prompt.certification_context'),
            );
        }

        return implode("\n", $lines);
    }

    private function maybeAutoTitle(
        AiChatConversation $conversation,
        AiChatMessage $userMessage,
        AiChatMessage $assistantMessage,
    ): void {
        if (! (bool) config('ai-chat.auto_title.enabled', true)) {
            return;
        }

        if ($conversation->title_manually_set) {
            return;
        }

        $completedAssistantCount = AiChatMessage::query()
            ->where('ai_chat_conversation_id', $conversation->id)
            ->where('role', AiChatMessageRole::Assistant->value)
            ->where('status', AiChatMessageStatus::Completed->value)
            ->count();

        if ($completedAssistantCount !== 1) {
            // 初回応答完了直後のみ AI に改題させる(2 回目以降のたびに変わると混乱するため)。
            return;
        }

        try {
            $title = $this->gemini->generateTitle($userMessage->content, $assistantMessage->content);
        } catch (\Throwable $e) {
            Log::channel('ai-chat')->warning('gemini auto-title generation threw', [
                'conversation_id' => $conversation->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if ($title === null || $title === '') {
            return;
        }

        $conversation->forceFill(['title' => $title])->save();
    }
}
