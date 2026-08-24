<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\User;

/**
 * AiChatConversation リソースに対する認可ポリシー(S-A-02)。
 *
 * - viewAny / create: 学習中(in_progress)の受講生のみ(ルート middleware `role:student` +
 *   `active-learning` と二重にはなるが、Policy 単体でも判定可能にしておく)
 * - view / update / delete / sendMessage: 会話オーナー本人のみ(他の受講生・コーチ・管理者は不可)
 */
class AiChatConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isEligibleStudent($user);
    }

    public function create(User $user): bool
    {
        return $this->isEligibleStudent($user);
    }

    public function view(User $user, AiChatConversation $conversation): bool
    {
        return $conversation->user_id === $user->id;
    }

    public function update(User $user, AiChatConversation $conversation): bool
    {
        return $conversation->user_id === $user->id;
    }

    public function delete(User $user, AiChatConversation $conversation): bool
    {
        return $conversation->user_id === $user->id;
    }

    public function sendMessage(User $user, AiChatConversation $conversation): bool
    {
        return $conversation->user_id === $user->id;
    }

    private function isEligibleStudent(User $user): bool
    {
        return $user->role === UserRole::Student && $user->status === UserStatus::InProgress;
    }
}
