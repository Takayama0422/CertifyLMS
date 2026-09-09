<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * コーチメモ(EnrollmentNote) の認可ポリシー。
 *
 * - 受講生: 閲覧含め一切不可(業務記録の性質を保つ)
 * - コーチ: 担当資格に登録した受講生の受講登録のみ閲覧 / 追加可。編集 / 削除は自分が作成したメモのみ
 * - 管理者: 任意の受講登録に閲覧 / 追加可。編集 / 削除は全メモ可
 *
 * 親 Enrollment が削除(SoftDelete)された場合、配下のメモは一覧から除外する(`viewAny` で trashed を弾く)。
 */
class EnrollmentNotePolicy
{
    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        return ! $enrollment->trashed() && $this->canManage($user, $enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $this->canManage($user, $enrollment);
    }

    public function update(User $user, EnrollmentNote $note): bool
    {
        return $user->role === UserRole::Admin
            || ($user->role === UserRole::Coach && $note->user_id === $user->id);
    }

    public function delete(User $user, EnrollmentNote $note): bool
    {
        return $this->update($user, $note);
    }

    private function canManage(User $user, Enrollment $enrollment): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coach => $this->isAssignedCoach($enrollment, $user),
            default => false,
        };
    }

    private function isAssignedCoach(Enrollment $enrollment, User $coach): bool
    {
        $enrollment->loadMissing('certification.coaches');

        return $enrollment->certification?->coaches->contains('id', $coach->id) ?? false;
    }
}
