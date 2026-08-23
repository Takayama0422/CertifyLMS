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
 * - 編集 / 削除(自己): 投稿者本人のみ、かつスレッド側と同じ可視性チェックを併用する(資格が公開停止に
 *   なった後や、コーチが担当を外された後は、自分の回答であっても編集・削除できない)
 * - 削除: 投稿者本人(可視性チェックあり) または 管理者。Blade 側は管理者・非管理者を問わず
 *   `can('delete', $reply)` で削除ボタンの表示可否を判定するため、ここは常に「削除ボタンを
 *   見せてよいか」を返す(QaThreadPolicy::delete と同様)
 * - 削除(モデレーション): 管理者のみ。`QaThreadModerationController::destroyReply` が実際に認可する
 *   際に使う、管理者専用の別 ability
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
        return $reply->user_id === $auth->id && $this->visible($auth, $reply->thread);
    }

    public function delete(User $auth, QaReply $reply): bool
    {
        if ($auth->role === UserRole::Admin) {
            return true;
        }

        return $reply->user_id === $auth->id && $this->visible($auth, $reply->thread);
    }

    /**
     * 管理者モデレーションによる削除。
     */
    public function moderateDelete(User $auth, QaReply $reply): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
