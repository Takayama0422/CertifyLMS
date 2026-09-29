<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

/**
 * `StoreConversationAction` の戻り値。
 *
 * `created` は「新規作成したか(true)」「既存会話を再利用したか(false)」を表す。
 * ウィジェット JSON レスポンスの HTTP ステータス(200 = 再開 / 201 = 新規作成)の決定に使う。
 */
final class AiChatConversationResult
{
    public function __construct(
        public readonly AiChatConversation $conversation,
        public readonly bool $created,
    ) {}
}
