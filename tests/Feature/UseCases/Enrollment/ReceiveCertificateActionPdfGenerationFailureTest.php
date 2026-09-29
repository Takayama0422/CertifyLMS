<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificatePdfGenerationException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use App\Services\CertificatePdfGenerator;
use App\UseCases\Enrollment\ReceiveCertificateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * PDF 生成が失敗した場合、`Certificate\IssueAction` の DB::transaction が
 * 呼び出し元 `ReceiveCertificateAction` の DB::transaction を巻き込んで ROLLBACK することを検証する。
 *
 * Certificate レコードだけでなく、Enrollment の状態(status=passed への更新 / passed_at)と
 * EnrollmentStatusLog の記録も巻き戻ることを固定する回帰テスト。
 * (現在の実装を変更するものではなく、既存の挙動をテストとして明示的に記録するために追加)
 */
class ReceiveCertificateActionPdfGenerationFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_enrollment_status_and_status_log_are_rolled_back_when_pdf_generation_fails(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);

        $generator = Mockery::mock(CertificatePdfGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(new CertificatePdfGenerationException);
        $this->app->instance(CertificatePdfGenerator::class, $generator);

        try {
            app(ReceiveCertificateAction::class)($enrollment);
            $this->fail('CertificatePdfGenerationException が throw されるはず');
        } catch (CertificatePdfGenerationException) {
            // 期待通り
        }

        $enrollment->refresh();
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->status);
        $this->assertNull($enrollment->passed_at);
        $this->assertDatabaseCount('enrollment_status_logs', 0);
        $this->assertDatabaseCount('certificates', 0);
    }
}
