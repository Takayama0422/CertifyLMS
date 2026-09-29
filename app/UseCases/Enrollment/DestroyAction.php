<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Enrollment\EnrollmentInvalidTransitionException;
use App\Models\Enrollment;
use App\Services\DefaultEnrollmentService;
use App\Services\EnrollmentStatsService;
use Illuminate\Support\Facades\DB;

/**
 * 受講生による受講解除(SoftDelete) Action。learning 状態の Enrollment のみ削除可。
 * passed / failed は履歴として残すため拒否する。
 *
 * 当該 Enrollment が受講生のデフォルト資格だった場合は、他の learning|passed 残存件数で自動振替 / NULL リセット。
 *
 * T-A-06: `status` 列は変えない SoftDelete のため `EnrollmentStatusChangeService::recordStatusChange()` を
 * 経由しないが、管理者ダッシュボードの集計は SoftDelete 除外で数えているため、削除も集計を変える。
 * `EnrollmentStatsService::invalidateAdminDashboardCache()` を直接呼んで無効化する
 * (コミット前に呼ぶと別リクエストが旧値でキャッシュを作り直すため `DB::afterCommit()` で遅延)。
 */
final class DestroyAction
{
    public function __construct(
        private readonly DefaultEnrollmentService $defaultEnrollmentService,
        private readonly EnrollmentStatsService $statsService,
    ) {}

    /**
     * @throws EnrollmentInvalidTransitionException
     */
    public function __invoke(Enrollment $enrollment): void
    {
        if ($enrollment->status !== EnrollmentStatus::Learning) {
            throw EnrollmentInvalidTransitionException::forDestroy();
        }

        DB::transaction(function () use ($enrollment) {
            $user = $enrollment->user;

            // 個人学習目標(EnrollmentGoal)は SoftDelete 非対応(物理削除のみ)のため、
            // 親 Enrollment の SoftDelete に連動して明示的に削除する(S-B-05 要件)。
            $enrollment->goals()->delete();

            $enrollment->delete();

            $this->defaultEnrollmentService->resolveAfterStatusChange($user, $enrollment);

            DB::afterCommit(function (): void {
                $this->statsService->invalidateAdminDashboardCache();
            });
        });
    }
}
