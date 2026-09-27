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
 * - viewAny / create / view / update / delete / sendMessage: いずれも学習中(in_progress)の
 *   受講生のみ(ルート middleware `role:student` + `active-learning` と重複するが、この時点の
 *   `active-learning` は未ログインしか判定しないため、状態判定は Policy 側で完結させる)。
 * - view / update / delete / sendMessage はさらに会話オーナー本人のみ(他の受講生・コーチ・管理者は不可)。
 *   受講中に作成した会話は、卒業(修了)後は直リンクでも一切操作できなくなる。
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
        return $this->isEligibleStudent($user) && $conversation->user_id === $user->id;
    }

    public function update(User $user, AiChatConversation $conversation): bool
    {
        return $this->isEligibleStudent($user) && $conversation->user_id === $user->id;
    }

    public function delete(User $user, AiChatConversation $conversation): bool
    {
        return $this->isEligibleStudent($user) && $conversation->user_id === $user->id;
    }

    public function sendMessage(User $user, AiChatConversation $conversation): bool
    {
        return $this->isEligibleStudent($user) && $conversation->user_id === $user->id;
    }

    private function isEligibleStudent(User $user): bool
    {
        return $user->role === UserRole::Student && $user->status === UserStatus::InProgress;
    }
}
