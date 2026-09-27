<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

/**
 * 会話の削除(受講生本人の手動操作のみ。自動削除 / 一括クリーンアップはスコープ外)。
 * メッセージは `ai_chat_messages.ai_chat_conversation_id` の cascadeOnDelete で連動削除される。
 */
final class DestroyConversationAction
{
    public function __invoke(AiChatConversation $conversation): void
    {
        $conversation->delete();
    }
}
