<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Laravel 標準 `DatabaseNotification` の認可ポリシー。
 *
 * 通知の閲覧・既読化は本人宛のものに限る(他人宛の通知は閲覧も既読化もできない、要件シート S4)。
 * `notifiable_type` は User 固定運用(通知の受信者は User のみ)。
 */
class NotificationPolicy
{
    public function view(User $auth, DatabaseNotification $notification): bool
    {
        return $this->belongsToUser($auth, $notification);
    }

    public function update(User $auth, DatabaseNotification $notification): bool
    {
        return $this->belongsToUser($auth, $notification);
    }

    private function belongsToUser(User $auth, DatabaseNotification $notification): bool
    {
        return $notification->notifiable_type === User::class
            && $notification->notifiable_id === $auth->id;
    }
}
