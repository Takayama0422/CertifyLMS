<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * 通知(アプリ内 + メール)の配信対象から外れる利用者を判定する。要件シート S8 の「配信除外」規定:
 *
 * - 管理者は通知の受信側ではない(S-A-05 の記述より)
 * - 退会済 / 招待中のユーザーは対象外(要件シート S12-06)
 *
 * S-B-04(業務イベント通知)/ S-B-09(面談リマインダー)の両方が同じ規則を使う。
 * S-B-08(お知らせ配信)はこれに加えて「配信対象は受講生のみ・修了済は含めない」という追加規則を持つため、
 * ここでは共通規則のみを扱い、お知らせ側の追加判定は呼び出し側(AnnouncementController 配下)で行う。
 */
final class NotificationRecipientPolicy
{
    public static function eligibleForEventNotification(User $user): bool
    {
        return $user->role !== UserRole::Admin
            && $user->status !== UserStatus::Withdrawn
            && $user->status !== UserStatus::Invited;
    }
}
