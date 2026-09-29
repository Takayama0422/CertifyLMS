<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Enrollment;

use App\Models\Enrollment;
use App\Models\User;
use App\Services\Learning\ProgressSummary;
use App\UseCases\Enrollment\FetchProgressForViewerAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * コードレビュー指摘 8(T-A-03)の回帰テスト。
 * `EnrollmentController::show()` が `LearningProgressService` を直接注入して呼んでいた
 * (Controller にデータ取得ロジックが残っていた)ため、`FetchProgressForViewerAction` へ切り出した。
 * staff(admin/coach)のみ集計を返し、student には返さないという既存の挙動を検証する。
 */
class FetchProgressForViewerActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_progress_summary_for_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        $result = app(FetchProgressForViewerAction::class)($enrollment, $coach);

        $this->assertInstanceOf(ProgressSummary::class, $result);
    }

    public function test_returns_progress_summary_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        $result = app(FetchProgressForViewerAction::class)($enrollment, $admin);

        $this->assertInstanceOf(ProgressSummary::class, $result);
    }

    public function test_returns_null_for_student(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->learning()->create();

        $result = app(FetchProgressForViewerAction::class)($enrollment, $student);

        $this->assertNull($result, 'student 本人の画面には進捗集計を返さないはず');
    }
}
