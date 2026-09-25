<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 模試の「問題数」表示に対する回帰防止テスト。
 *
 * 支給 Blade は 4 経路すべてで `mock_exam_questions_count` を `?? 0` 付きで参照しており、
 * 取得側が件数を用意し忘れても例外にならず 0 と表示されてしまう。
 * 各経路で実際の問題件数が表示されることを固定する。
 */
class MockExamQuestionCountDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_index_shows_actual_question_count(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        $threeQuestions = MockExam::factory()->forCertification($certification)->create(['order' => 0]);
        MockExamQuestion::factory()->count(3)->forMockExam($threeQuestions)->create();

        $fiveQuestions = MockExam::factory()->forCertification($certification)->create(['order' => 1]);
        MockExamQuestion::factory()->count(5)->forMockExam($fiveQuestions)->create();

        $response = $this->actingAs($admin)->get(route('admin.mock-exams.index'));
        $response->assertStatus(200);

        $mockExams = $response->viewData('mockExams');

        $this->assertSame(
            3,
            $mockExams->firstWhere('id', $threeQuestions->id)->mock_exam_questions_count,
            '模試マスタ一覧が問題数を取得していない(Blade の ?? 0 により 0 と表示される)',
        );
        $this->assertSame(
            5,
            $mockExams->firstWhere('id', $fiveQuestions->id)->mock_exam_questions_count,
            '模試マスタ一覧が問題数を取得していない(Blade の ?? 0 により 0 と表示される)',
        );

        // 画面上も実件数が描画されていること(合格点セルは "60%" なので数値のみのセルとは衝突しない)
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/tabular-nums[^>]*>\s*3\s*<\/td>/u', $html);
        $this->assertMatchesRegularExpression('/tabular-nums[^>]*>\s*5\s*<\/td>/u', $html);
    }

    public function test_management_show_shows_actual_question_count(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $mockExam = MockExam::factory()->forCertification($certification)->create();
        MockExamQuestion::factory()->count(4)->forMockExam($mockExam)->create();

        $response = $this->actingAs($admin)->get(route('admin.mock-exams.show', $mockExam));
        $response->assertStatus(200);

        $this->assertSame(4, $response->viewData('mockExam')->mock_exam_questions_count);
        $this->assertMatchesRegularExpression('/tabular-nums[^>]*>\s*4\s*<\/span>\s*件/u', $response->getContent());
    }

    public function test_catalog_index_shows_actual_question_count(): void
    {
        [$student, $enrollment, $certification] = $this->makeLearningStudent();

        $mockExam = MockExam::factory()->forCertification($certification)->published()->create();
        MockExamQuestion::factory()->count(6)->forMockExam($mockExam)->create();

        $response = $this->actingAs($student)->get(route('mock-exam.catalog.index', $enrollment));
        $response->assertStatus(200);

        $this->assertSame(
            6,
            $response->viewData('mockExams')->firstWhere('id', $mockExam->id)->mock_exam_questions_count,
        );
        $this->assertMatchesRegularExpression('/tabular-nums[^>]*>\s*6\s*<\/span>\s*問/u', $response->getContent());
    }

    public function test_catalog_show_shows_actual_question_count(): void
    {
        [$student, $enrollment, $certification] = $this->makeLearningStudent();

        $mockExam = MockExam::factory()->forCertification($certification)->published()->create();
        MockExamQuestion::factory()->count(7)->forMockExam($mockExam)->withOptions()->create();

        $response = $this->actingAs($student)->get(route('mock-exam.catalog.show', [
            'enrollment' => $enrollment,
            'mockExam' => $mockExam,
        ]));
        $response->assertStatus(200);

        $this->assertSame(7, $response->viewData('mockExam')->mock_exam_questions_count);
        $this->assertMatchesRegularExpression('/>\s*7 問\s*</u', $response->getContent());
    }

    /**
     * @return array{0: User, 1: Enrollment, 2: Certification}
     */
    private function makeLearningStudent(): array
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        return [$student, $enrollment, $certification];
    }
}
