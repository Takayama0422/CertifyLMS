<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FlashesAiChatAvailability;
use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\UseCases\AiChat\DestroyConversationAction;
use App\UseCases\AiChat\StoreConversationAction;
use App\UseCases\AiChat\UpdateConversationAction;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AI 相談の会話 CRUD(新規作成 / 詳細 / タイトル編集 / 削除)。
 *
 * - store: ウィジェット(JSON 経由、`Accept: application/json`)とフル画面の新規会話モーダル
 *   (素の HTML form POST)の 2 経路を同一エンドポイントで受ける。JSON 経由は
 *   `{conversation: {...}}` を 200(既存会話再開)/ 201(新規作成)で返し、HTML 経由は
 *   会話詳細へ redirect する(初回メッセージがあれば同期送信を待ってから)。
 * - show: フル画面表示(HTML)と、ウィジェットの履歴復元(JSON, `{messages: [...]}`)の 2 経路。
 */
class AiChatConversationController extends Controller
{
    use FlashesAiChatAvailability;

    public function store(StoreConversationRequest $request, StoreConversationAction $action): JsonResponse|RedirectResponse
    {
        $result = $action(
            $request->user(),
            $request->validated('section_id'),
            $request->validated('message'),
        );

        if ($request->wantsJson()) {
            return response()->json([
                'conversation' => [
                    'id' => $result->conversation->id,
                    'title' => $result->conversation->title,
                ],
            ], $result->created ? 201 : 200);
        }

        return redirect()->route('ai-chat.conversations.show', $result->conversation);
    }

    public function show(AiChatConversation $conversation, Request $request): Renderable|JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load('messages');

        if ($request->wantsJson()) {
            return response()->json([
                'messages' => $conversation->messages
                    ->map(fn (AiChatMessage $m) => $this->messagePayload($m))
                    ->values(),
            ]);
        }

        $this->flashUnavailableNoticeIfKeyMissing();

        return view('ai-chat.show', ['conversation' => $conversation]);
    }

    public function update(
        UpdateConversationRequest $request,
        AiChatConversation $conversation,
        UpdateConversationAction $action,
    ): RedirectResponse {
        $action($conversation, (string) $request->validated('title'));

        return redirect()
            ->route('ai-chat.conversations.show', $conversation)
            ->with('success', '会話タイトルを更新しました。');
    }

    public function destroy(AiChatConversation $conversation, DestroyConversationAction $action): RedirectResponse
    {
        $this->authorize('delete', $conversation);

        $action($conversation);

        return redirect()
            ->route('ai-chat.index')
            ->with('success', '会話を削除しました。');
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
