<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\User;

/**
 * 管理者お知らせ配信の認可ルール。admin のみ全操作可能(要件シート S4)。
 * 配信は不可逆のため update / delete の ability は用意しない(編集・取消の経路自体を設けない)。
 */
class AnnouncementPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function view(User $auth, Announcement $announcement): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
