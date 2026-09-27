<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AI 相談メッセージの発言者ロール。
 *
 * Gemini API の `contents[].role` にそのまま対応するわけではない(Gemini 側は `user` / `model`)。
 * Assistant → `model` の変換は `GeminiClient` 側で行う。
 */
enum AiChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
