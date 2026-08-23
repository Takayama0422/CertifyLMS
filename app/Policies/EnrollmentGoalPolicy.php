<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

/**
 * 個人学習目標(EnrollmentGoal) の認可ポリシー。
 *
 * 追加 / 編集 / 削除 / 達成マーク / 達成解除は受講生本人のみ可(コーチ / 管理者 / 他受講生は不可)。
 * 閲覧(本人 / 担当コーチ / 管理者)は受講登録詳細画面自体の認可(EnrollmentPolicy::view)に委ねるため、
 * 本 Policy では操作系 ability のみを扱う。
 */
class EnrollmentGoalPolicy
{
    public function create(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student && $enrollment->user_id === $user->id;
    }

    public function update(User $user, EnrollmentGoal $goal): bool
    {
        return $user->role === UserRole::Student && $goal->enrollment->user_id === $user->id;
    }

    public function delete(User $user, EnrollmentGoal $goal): bool
    {
        return $this->update($user, $goal);
    }

    public function markAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->update($user, $goal) && $goal->achieved_at === null;
    }

    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->update($user, $goal) && $goal->achieved_at !== null;
    }
}
