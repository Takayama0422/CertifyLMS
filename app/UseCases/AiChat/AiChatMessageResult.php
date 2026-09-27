<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatMessage;

/**
 * `StoreMessageAction` の戻り値。Controller が HTTP レスポンス(200 / 502)を組み立てるための材料。
 */
final class AiChatMessageResult
{
    public function __construct(
        public readonly AiChatMessage $userMessage,
        public readonly AiChatMessage $assistantMessage,
        public readonly bool $successful,
        public readonly ?int $upstreamStatus,
    ) {}
}
