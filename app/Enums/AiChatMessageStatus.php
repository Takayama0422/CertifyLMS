<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AI 相談メッセージの処理状態。
 *
 * - Pending: 現状は永続化しない(同期応答のみ採用のため一時的にも DB へは残さない)。
 *   将来ストリーミング対応する際の予約値として Enum には残す。
 * - Completed: 送信 / 応答とも成功。
 * - Error: AI 応答取得に失敗(受講生のメッセージ自体は Completed のまま残る)。
 */
enum AiChatMessageStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Error = 'error';
}
