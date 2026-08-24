<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Enums\UserRole;
use App\Http\Controllers\EnrollmentController;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Learning\ProgressSummary;
use App\Services\LearningProgressService;

/**
 * 受講登録詳細画面向けに、閲覧者ロールに応じた学習進捗サマリを取得するユースケース。
 *
 * staff(coach / admin)のみ 4 階層(Section/Chapter/Part/資格)の進捗集計を必要とする
 * (student 本人の画面には進捗カードを出さないため計算不要)。
 *
 * @see EnrollmentController::show()
 */
final class FetchProgressForViewerAction
{
    public function __construct(
        private readonly LearningProgressService $progressService,
    ) {}

    public function __invoke(Enrollment $enrollment, User $viewer): ?ProgressSummary
    {
        if (! in_array($viewer->role, [UserRole::Coach, UserRole::Admin], true)) {
            return null;
        }

        $enrollment->loadMissing(['user']);

        return $this->progressService->summarize($enrollment);
    }
}
