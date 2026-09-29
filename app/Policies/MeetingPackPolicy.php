<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MeetingPackStatus;
use App\Enums\UserRole;
use App\Models\MeetingPack;
use App\Models\User;

/**
 * 面談パックマスタの認可ポリシー。全操作を admin のみに許可する（coach / student はアクセス不可）。
 * 「公開中は削除不可」等の業務ルールは Policy ではなく `MeetingPack\DestroyAction` 側で判定する
 * （Certification の DestroyAction と同じ責務分離）。
 *
 * `purchase` のみ例外で、受講生(student)側の購入動線(S-A-03)から呼ばれる。
 * 「公開中でない面談パックは URL 直指定でも購入できない」を Policy レイヤーで担保する
 * （受講生本人が学習中かどうかは `MeetingQuotaPolicy::purchase` 側で別途判定する）。
 */
class MeetingPackPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function view(User $auth, MeetingPack $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function update(User $auth, MeetingPack $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function delete(User $auth, MeetingPack $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function publish(User $auth, MeetingPack $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function archive(User $auth, MeetingPack $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function unarchive(User $auth, MeetingPack $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    /**
     * 受講生による購入対象としての妥当性。公開中(published)の面談パックのみ購入可。
     */
    public function purchase(User $auth, MeetingPack $plan): bool
    {
        return $plan->status === MeetingPackStatus::Published;
    }
}
