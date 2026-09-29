<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * 受講生本人の面談回数履歴閲覧に関する認可。
 */
class MeetingQuotaPolicy
{
    /**
     * 面談回数履歴の閲覧。本人のみ可。
     */
    public function viewHistory(User $auth, User $target): bool
    {
        return $auth->id === $target->id;
    }

    /**
     * 追加面談パックの購入(S-A-03)。学習中(in_progress)の受講生のみ可。
     *
     * ルート側の `role:student` + `active-learning` Middleware でも同じ条件を絞り込むが、
     * 本 Policy は Middleware の実装状況に依存せず単独で正しく判定できるようにするための
     * 独立した認可レイヤーとして用意する(共有 Middleware は他チケット B-B-16 の修正対象で、
     * 本チケット時点では未修正のため学習中判定を行わない状態)。
     */
    public function purchase(User $auth): bool
    {
        return $auth->role === UserRole::Student && $auth->status === UserStatus::InProgress;
    }
}
