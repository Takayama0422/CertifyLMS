<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 質問掲示板スレッドの解決状態。
 *
 * Open(未解決) → Resolved(解決済) の一方向ではなく、投稿者本人が resolve / unresolve で
 * 双方向に切り替えられる(`QaThread::resolved_at` の有無と対にして持つ)。
 */
enum QaThreadStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => '未解決',
            self::Resolved => '解決済',
        };
    }
}
