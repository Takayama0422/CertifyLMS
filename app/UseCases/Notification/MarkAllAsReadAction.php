<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Models\User;

/**
 * 認証ユーザー宛の未読通知をすべて既読化する。1 UPDATE 文で完結させ、件数分の N+1 更新を避ける。
 */
final class MarkAllAsReadAction
{
    public function __invoke(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
