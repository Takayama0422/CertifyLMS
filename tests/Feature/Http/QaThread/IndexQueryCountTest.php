<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 質問掲示板一覧(`qa-board.index`)の N+1 非回帰を検証する Feature テスト。
 * 投稿者 / 対象資格 / 回答数を一括取得することで、スレッドの件数を増やしても発行クエリ数が
 * ほぼ増えないことを担保する(`tests/Feature/Http/MockExam/MockExamIndexQueryCountTest.php` と同型)。
 */
class IndexQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_query_count_does_not_grow_with_thread_count(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $this->createThreadsWithReplies($certification, 2);

        $baseline = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('qa-board.index'))
        );

        $this->createThreadsWithReplies($certification, 10);

        $scaled = $this->countQueriesFor(
            fn () => $this->actingAs($student)->get(route('qa-board.index'))
        );

        $this->assertLessThanOrEqual(
            $baseline + 3,
            $scaled,
            "質問掲示板一覧で N+1 が再発している(基準 {$baseline} → 増加後 {$scaled})。投稿者 / 対象資格 / 回答数を Eager Loading + withCount で一括取得しているか確認",
        );
    }

    /**
     * レビュー指摘 5: クエリ本数だけを見ていると、一覧が 500 になったり絞り込みが壊れて 0 件になっても
     * 検出できない。応答が正常であることに加え、期待件数のスレッドカードが実際に描画されることを検証する。
     */
    public function test_index_renders_expected_number_of_threads(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $threads = QaThread::factory()->for($certification)->count(3)->create();

        $response = $this->actingAs($student)->get(route('qa-board.index'));

        $response->assertOk();
        $paginator = $response->viewData('threads');
        $this->assertSame(3, $paginator->total());

        foreach ($threads as $thread) {
            $response->assertSee(route('qa-board.show', $thread), false);
        }
    }

    private function createThreadsWithReplies(Certification $certification, int $count): void
    {
        $threads = QaThread::factory()->for($certification)->count($count)->create();
        foreach ($threads as $thread) {
            $thread->replies()->create([
                'user_id' => User::factory()->student()->create()->id,
                'body' => 'テスト回答',
            ]);
        }
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
