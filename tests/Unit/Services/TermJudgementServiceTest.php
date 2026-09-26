<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\TermType;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Services\TermJudgementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TermJudgementServiceTest extends TestCase
{
    use RefreshDatabase;

    private TermJudgementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TermJudgementService::class);
    }

    public function test_returns_basic_learning_when_no_mock_exam_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::BasicLearning->value]);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::BasicLearning, $term);
    }

    public function test_returns_basic_learning_when_only_not_started_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create();
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'not_started']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::BasicLearning, $term);
    }

    public function test_returns_basic_learning_when_only_canceled_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::MockPractice->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'canceled']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::BasicLearning, $term);
        $this->assertSame(TermType::BasicLearning, $enrollment->refresh()->current_term);
    }

    public function test_returns_mock_practice_when_in_progress_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::BasicLearning->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'in_progress']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::MockPractice, $term);
        $this->assertSame(TermType::MockPractice, $enrollment->refresh()->current_term);
    }

    public function test_returns_mock_practice_when_submitted_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create();
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'submitted']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::MockPractice, $term);
    }

    public function test_returns_mock_practice_when_graded_session_exists(): void
    {
        $enrollment = Enrollment::factory()->create();
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'graded']);

        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::MockPractice, $term);
    }

    /**
     * 複数セッションが混在しても判定が崩れないこと(PM回答: 混在ケースのテスト追加)。
     * 実践タームの根拠になるのは in_progress / submitted / graded のみ。canceled / not_started が
     * いくつ混ざっていても、根拠となるセッションが 1 件でもあれば実践ターム、無ければ基礎タームのまま。
     *
     * @param array<int, string> $statuses
     */
    #[DataProvider('mixedSessionStatuses')]
    public function test_judges_term_correctly_when_multiple_sessions_are_mixed(array $statuses, TermType $expected): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::BasicLearning->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        foreach ($statuses as $status) {
            MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => $status]);
        }

        $term = $this->service->recalculate($enrollment);

        $this->assertSame($expected, $term);
        $this->assertSame($expected, $enrollment->refresh()->current_term);
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: TermType}>
     */
    public static function mixedSessionStatuses(): array
    {
        return [
            'canceled と in_progress が混在 → 実践' => [['canceled', 'in_progress'], TermType::MockPractice],
            'canceled と submitted が混在 → 実践' => [['canceled', 'submitted'], TermType::MockPractice],
            'canceled と graded が混在 → 実践' => [['canceled', 'graded'], TermType::MockPractice],
            'not_started と in_progress が混在 → 実践' => [['not_started', 'in_progress'], TermType::MockPractice],
            'canceled が複数 + in_progress 1 件 → 実践' => [['canceled', 'canceled', 'in_progress'], TermType::MockPractice],
            'canceled と not_started のみ → 基礎' => [['canceled', 'not_started'], TermType::BasicLearning],
            'canceled が複数のみ → 基礎' => [['canceled', 'canceled', 'canceled'], TermType::BasicLearning],
        ];
    }

    /**
     * 進行中のセッションを 1 件キャンセルしても、別の有効なセッションが残っていれば実践タームのまま。
     */
    public function test_stays_mock_practice_when_one_of_two_active_sessions_is_canceled(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::MockPractice->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        $canceling = MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'in_progress']);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'graded']);

        $canceling->update(['status' => 'canceled']);
        $term = $this->service->recalculate($enrollment);

        $this->assertSame(TermType::MockPractice, $term);
    }

    public function test_does_not_update_when_current_term_already_matches(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::BasicLearning->value]);
        $originalUpdatedAt = $enrollment->updated_at;

        sleep(1);
        $this->service->recalculate($enrollment);

        $this->assertEquals($originalUpdatedAt->timestamp, $enrollment->refresh()->updated_at->timestamp);
    }
}
