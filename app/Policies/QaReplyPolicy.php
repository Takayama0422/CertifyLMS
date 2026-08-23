<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\Concerns\ChecksQaThreadVisibility;

/**
 * 質問掲示板の回答に対する認可ポリシー。
 *
 * - 投稿: 受講生 / コーチ(管理者は回答不可)。対象スレッドが閲覧可能であること
 * - 編集 / 削除(自己): 投稿者本人のみ
 * - 削除(モデレーション): 管理者のみ
 */
class QaReplyPolicy
{
    use ChecksQaThreadVisibility;

    public function create(User $auth, QaThread $thread): bool
    {
        if (! in_array($auth->role, [UserRole::Student, UserRole::Coach], true)) {
            return false;
        }

        return $this->visible($auth, $thread);
    }

    public function update(User $auth, QaReply $reply): bool
    {
        return $reply->user_id === $auth->id;
    }

    public function delete(User $auth, QaReply $reply): bool
    {
        return $reply->user_id === $auth->id;
    }

    /**
     * 管理者モデレーションによる削除。
     */
    public function moderateDelete(User $auth, QaReply $reply): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
