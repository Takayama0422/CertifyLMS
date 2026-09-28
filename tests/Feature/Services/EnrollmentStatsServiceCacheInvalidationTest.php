<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentStatsService;
use App\Services\EnrollmentStatusChangeService;
use App\UseCases\Enrollment\DestroyAction;
use App\UseCases\Enrollment\FailAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * コードレビュー指摘 1・2(T-A-06)の回帰テスト。
 *
 * 指摘 1: 受講解除(DestroyAction)がキャッシュ無効化のチョークポイント
 *         (EnrollmentStatusChangeService::recordStatusChange)を経由しないため、
 *         解除後も古い集計が残っていた。
 * 指摘 2: キャッシュ削除がトランザクションのコミット前に実行されていたため、
 *         コミット前に他の管理者がダッシュボードを開くと旧値でキャッシュを作り直してしまっていた。
 */
class EnrollmentStatsServiceCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fail_action_invalidates_admin_dashboard_cache_after_commit(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        // ダッシュボードを一度表示してキャッシュを温めておく(learning_count=1 が入る)。
        $statsService = app(EnrollmentStatsService::class);
        $before = $statsService->adminKpi();
        $statsService->completionRateByCertification();
        $this->assertSame(1, $before['learning_count']);
        $this->assertTrue(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertTrue(Cache::has(config('dashboard.admin_completion_rate_cache_key')));

        app(FailAction::class)($enrollment, $admin, '検証用');

        // コミット後まで実行されるため、Action 呼び出しが返った時点で無効化済みのはず。
        $this->assertFalse(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertFalse(Cache::has(config('dashboard.admin_completion_rate_cache_key')));

        $after = $statsService->adminKpi();
        $this->assertSame(0, $after['learning_count']);
        $this->assertSame(1, $after['failed_count']);
    }

    public function test_status_change_does_not_invalidate_cache_when_transaction_rolls_back(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        $statsService = app(EnrollmentStatsService::class);
        $statsService->adminKpi();
        $this->assertTrue(Cache::has(config('dashboard.admin_kpi_cache_key')));

        try {
            DB::transaction(function () use ($enrollment) {
                app(EnrollmentStatusChangeService::class)->recordStatusChange(
                    $enrollment,
                    fromStatus: EnrollmentStatus::Learning,
                    toStatus: EnrollmentStatus::Failed,
                    changedBy: null,
                    reason: 'ロールバック検証',
                );

                // トランザクション確定前に強制的に失敗させ、ロールバックさせる。
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // 期待どおりロールバックされた。
        }

        // コミット前に Cache::forget を実行していた旧実装ではここでキャッシュが消えてしまう。
        // afterCommit() 経由の新実装では、コミットされなかった以上キャッシュも消えない。
        $this->assertTrue(
            Cache::has(config('dashboard.admin_kpi_cache_key')),
            'ロールバックされたトランザクション内の状態変更でキャッシュが無効化されてはならない',
        );
    }

    public function test_destroy_action_invalidates_admin_dashboard_cache(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        $statsService = app(EnrollmentStatsService::class);
        $before = $statsService->adminKpi();
        $this->assertSame(1, $before['learning_count']);
        $this->assertTrue(Cache::has(config('dashboard.admin_kpi_cache_key')));

        app(DestroyAction::class)($enrollment);

        // 受講解除(SoftDelete)は recordStatusChange() を経由しないが、それでも即時無効化されること。
        $this->assertFalse(Cache::has(config('dashboard.admin_kpi_cache_key')));

        $after = $statsService->adminKpi();
        $this->assertSame(0, $after['learning_count']);
        $this->assertSame(0, $after['total']);
    }
}
