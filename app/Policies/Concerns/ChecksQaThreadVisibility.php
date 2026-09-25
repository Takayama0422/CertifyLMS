<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問掲示板スレッドの閲覧可否ルールを QaThreadPolicy / QaReplyPolicy で共有するための trait。
 *
 * - admin: 常に可(公開停止中の資格も含め全件)
 * - coach: 担当資格 かつ 公開中の資格のみ
 * - student: 公開中の資格のみ(受講の有無を問わない)
 */
trait ChecksQaThreadVisibility
{
    private function visible(User $auth, QaThread $thread): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $thread->certification->status === CertificationStatus::Published
                && in_array($thread->certification_id, $auth->coachingCertificationIds(), true),
            UserRole::Student => $thread->certification->status === CertificationStatus::Published,
        };
    }
}
