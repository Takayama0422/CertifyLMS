<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enrollment 状態遷移の監査ログ(`EnrollmentStatusLog`)を INSERT する Service。
 *
 * 呼出側 Action がトランザクション内で recordStatusChange() を呼ぶ前提。本 Service 自体は
 * DB::transaction() を持たない(`backend-services.md` の規約準拠、ステートレス INSERT only)。
 *
 * `final` 不採用: Mockery で recordStatusChange を mock してトランザクション原子性の rollback 検証を
 * Action テストで行う可能性があるため(`UserStatusChangeService` と同じ判断軸)。
 *
 * T-A-06: 受講登録の初回記録 / 合格 / 不合格等、`status` 列が遷移する経路は本メソッドに集約されているため、
 * ここを管理者ダッシュボード集計キャッシュ(`EnrollmentStatsService`)の無効化チョークポイントとして使う。
 * ただし受講解除(SoftDelete、`status` 列は変えない)はこのメソッドを通らないため、
 * `App\UseCases\Enrollment\DestroyAction` 側で別途 `EnrollmentStatsService::invalidateAdminDashboardCache()`
 * を呼んでいる。
 */
final class EnrollmentStatusChangeService
{
    public function __construct(
        private readonly EnrollmentStatsService $statsService,
    ) {}

    /**
     * @param Enrollment $enrollment 状態遷移する対象 Enrollment
     * @param ?EnrollmentStatus $fromStatus 遷移前ステータス(初回登録時のみ null、それ以降は必須)
     * @param EnrollmentStatus $toStatus 遷移後ステータス
     * @param ?User $changedBy 操作者(null はシステム自動 = Schedule Command 等)
     * @param ?string $reason 変更理由(任意、UI 表示用)
     */
    public function recordStatusChange(
        Enrollment $enrollment,
        ?EnrollmentStatus $fromStatus,
        EnrollmentStatus $toStatus,
        ?User $changedBy,
        ?string $reason = null,
    ): EnrollmentStatusLog {
        $log = $enrollment->statusLogs()->create([
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'changed_by_user_id' => $changedBy?->id,
            'changed_reason' => $reason,
            'changed_at' => now(),
        ]);

        // T-A-06: 受講状態が遷移したので、管理者ダッシュボードの集計キャッシュ(KPI / 資格別修了率)を
        // 無効化する。呼出元は必ずトランザクション内のため、コミット前に消すと別リクエストが旧値で
        // キャッシュを作り直してしまう。DB::afterCommit() でコミット後まで遅延させる。
        DB::afterCommit(function (): void {
            $this->statsService->invalidateAdminDashboardCache();
        });

        return $log;
    }
}
