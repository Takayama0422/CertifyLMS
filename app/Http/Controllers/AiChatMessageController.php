<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\UseCases\AiChat\StoreMessageAction;
use Illuminate\Http\JsonResponse;

/**
 * AI 相談メッセージ送信(`POST /ai-chat/conversations/{conversation}/messages`)。
 *
 * `resources/js/ai-chat/chat-client.js` の `sendSync()` が唯一の呼び出し元(常に
 * `Accept: application/json` で fetch する)。
 *
 * - 成功: 200 + `{user_message, assistant_message, conversation}`
 * - 1 日の送信上限超過: `AiChatDailyLimitExceededException`(429、JSON はステータスのみで足りるため
 *   Controller では特別なハンドリングをせず Handler のデフォルト JSON レンダリングに任せる)
 * - Gemini 応答失敗(API キー未設定 / 通信エラー / 空応答など): 502 + `{message, upstream_status}`
 *   (受講生のメッセージ自体は既に保存済みなので同じ内容を送り直せる)
 *
 * @throws AiChatDailyLimitExceededException
 */
class AiChatMessageController extends Controller
{
    public function store(
        StoreMessageRequest $request,
        AiChatConversation $conversation,
        StoreMessageAction $action,
    ): JsonResponse {
        $result = $action($request->user(), $conversation, (string) $request->validated('content'));

        if (! $result->successful) {
            return response()->json([
                'message' => 'AI からの応答取得に失敗しました。同じ内容で再度お試しください。',
                'upstream_status' => $result->upstreamStatus,
            ], 502);
        }

        $conversation->refresh();

        return response()->json([
            'user_message' => $this->messagePayload($result->userMessage),
            'assistant_message' => $this->messagePayload($result->assistantMessage),
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(AiChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'content' => $message->content,
            'status' => $message->status->value,
            'response_time_ms' => $message->response_time_ms,
            'output_tokens' => $message->output_tokens,
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
