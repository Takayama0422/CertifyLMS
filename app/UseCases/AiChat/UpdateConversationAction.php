<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

/**
 * 会話タイトルの手動編集。以後 AI による自動改題の対象から外すため `title_manually_set` を立てる。
 */
final class UpdateConversationAction
{
    public function __invoke(AiChatConversation $conversation, string $title): AiChatConversation
    {
        $conversation->forceFill([
            'title' => $title,
            'title_manually_set' => true,
        ])->save();

        return $conversation;
    }
}
