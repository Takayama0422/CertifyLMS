<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\MeetingController;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 予約画面のエントリポイント(URL に Enrollment 無し)の表示に必要なデータを取得するユースケース。
 * `resolve-default-enrollment` Middleware が default 資格に redirect するため、本 Action に到達するのは
 * default 未設定 + 残存 Enrollment が 0 件 or 2+ 件のケース。
 *
 * @see MeetingController::createFallback()
 */
final class CreateFallbackAction
{
    /**
     * @return Collection<int, Enrollment>
     */
    public function __invoke(?User $user): Collection
    {
        return $user
            ?->enrollments()
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->with('certification')
            ->get() ?? collect();
    }
}
