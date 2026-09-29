<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知 1 件を既読化する。既に既読の場合は何もしない(冪等)。
 */
final class MarkAsReadAction
{
    public function __invoke(DatabaseNotification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->markAsRead();
        }
    }
}
