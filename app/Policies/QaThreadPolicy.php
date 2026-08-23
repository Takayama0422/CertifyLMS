<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\Concerns\ChecksQaThreadVisibility;

/**
 * 質問掲示板スレッドの認可ポリシー。
 *
 * - 閲覧: 受講生 = 公開中の資格すべて / コーチ = 担当かつ公開中の資格のみ / 管理者 = 全件(公開停止含む)
 * - 投稿: 受講生のみ。「対象資格が公開中であること」は入力検証(`QaThread/StoreRequest` の `exists` ルール)側の
 *   責務とし、ここでは「そもそも投稿という操作ができるロールか」のみを判定する(create 時点では対象資格が
 *   route パラメータではなく入力値のため、Model インスタンスを引数に取れない)
 * - 編集 / 解決マーク切替: 投稿者本人(受講生)のみ、かつ閲覧可能であること
 * - 削除: 投稿者本人(受講生、かつ閲覧可能であること) または 管理者。
 *   Blade 側は管理者・非管理者を問わず `can('delete', $thread)` で削除ボタンの表示可否を判定するため、
 *   ここは常に「削除ボタンを見せてよいか」を返す。実際の削除処理の業務規則(投稿者本人 = 回答 0 件のみ、
 *   管理者 = 件数を問わない)は `App\UseCases\QaThread\DestroyAction` / `AdminDestroyAction` 側で分岐する。
 *   `moderateDelete` は管理者ルート(`QaThreadModerationController::destroy`)が実際に認可する際に使う、
 *   管理者専用の別 ability(自己削除の業務規則と混同しないため)
 */
class QaThreadPolicy
{
    use ChecksQaThreadVisibility;

    public function viewAny(User $auth): bool
    {
        return in_array($auth->role, [UserRole::Admin, UserRole::Coach, UserRole::Student], true);
    }

    public function view(User $auth, QaThread $thread): bool
    {
        return $this->visible($auth, $thread);
    }

    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Student;
    }

    public function update(User $auth, QaThread $thread): bool
    {
        return $this->isOwner($auth, $thread) && $this->visible($auth, $thread);
    }

    public function delete(User $auth, QaThread $thread): bool
    {
        if ($auth->role === UserRole::Admin) {
            return true;
        }

        return $this->isOwner($auth, $thread) && $this->visible($auth, $thread);
    }

    public function resolve(User $auth, QaThread $thread): bool
    {
        return $this->isOwner($auth, $thread) && $this->visible($auth, $thread);
    }

    public function unresolve(User $auth, QaThread $thread): bool
    {
        return $this->isOwner($auth, $thread) && $this->visible($auth, $thread);
    }

    /**
     * 管理者モデレーションによる削除。回答件数の制約なし(自己削除の業務規則とは別ability)。
     */
    public function moderateDelete(User $auth, QaThread $thread): bool
    {
        return $auth->role === UserRole::Admin;
    }

    private function isOwner(User $auth, QaThread $thread): bool
    {
        return $auth->role === UserRole::Student && $thread->user_id === $auth->id;
    }
}
