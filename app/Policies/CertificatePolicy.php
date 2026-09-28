<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\User;

/**
 * 修了証(Certificate)のダウンロード認可ルール。
 *
 * - admin: 全件ダウンロード可
 * - student: 本人(`certificate->user_id === $auth->id`)のみ。Enrollment / User のステータスは問わない
 *   (修了証は永続資産のため、学習中以外(修了 / 退会前)でもダウンロード可)
 * - coach: 当該修了証の資格(`certification`)の担当コーチのみ(`certification_coach_assignments` の現役行)
 */
class CertificatePolicy
{
    public function download(User $auth, Certificate $certificate): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Student => $certificate->user_id === $auth->id,
            UserRole::Coach => $this->assignedCoach($auth, $certificate),
        };
    }

    private function assignedCoach(User $coach, Certificate $certificate): bool
    {
        return $certificate->certification()
            ->whereHas('coaches', fn ($query) => $query->where('users.id', $coach->id))
            ->exists();
    }
}
