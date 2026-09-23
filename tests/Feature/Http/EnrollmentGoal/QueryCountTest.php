<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 受講登録詳細画面(`enrollments.show`)の個人目標一覧の N+1 非回帰を検証する Feature テスト。
 *
 * 目標ごとに `@can` で評価される Policy(markAchieved / unmarkAchieved / update / delete)が
 * `$goal->enrollment->…` を参照するため、`enrollment` リレーションを事前ロードしておかないと
 * 目標 1 件につき問い合わせが 1 本増える(手本: DashboardQueryCountTest)。
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_list_query_count_does_not_grow_with_goal_count(): void
    {
        // Arrange: 受講生本人 + 目標 2 件(基準)
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->for(Certification::factory()->published())->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($enrollment)->count(2)->create();

        // Act: 基準のクエリ数を計測 → 目標を 8 件追加して再計測
        $baseline = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('enrollments.show', $enrollment))
        );
        EnrollmentGoal::factory()->forEnrollment($enrollment)->count(8)->create();
        $scaled = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('enrollments.show', $enrollment))
        );

        // Assert: 目標が増えても発行クエリ数はほぼ一定(N+1 なら件数分増える)
        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "受講登録詳細の個人目標一覧で N+1 が再発している (基準 {$baseline} → 増加後 {$scaled})。goals リレーションで enrollment を事前ロードしているか確認",
        );
    }

    private function countQueriesFor(\Closure $closure): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $closure();

        return $count;
    }
}
