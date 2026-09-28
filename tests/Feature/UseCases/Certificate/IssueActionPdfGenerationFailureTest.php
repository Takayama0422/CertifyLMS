<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Certificate;

use App\Exceptions\Certification\CertificatePdfGenerationException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfGenerator;
use App\UseCases\Certificate\IssueAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * PDF 生成失敗時に修了証が未発行のまま保たれることを検証する。
 *
 * `CertificatePdfGenerator` を失敗するダブルに差し替え、`IssueAction` の
 * `DB::transaction` が ROLLBACK して Certificate レコードが残らないことを確認する
 * (`tests/Feature/UseCases/Certificate/IssueActionTest.php` の正常系とは別ファイルとして追加し、
 * 既存テストは変更しない)。
 */
class IssueActionPdfGenerationFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_certificate_is_not_persisted_when_pdf_generation_fails(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();

        $generator = Mockery::mock(CertificatePdfGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(new CertificatePdfGenerationException);
        $this->app->instance(CertificatePdfGenerator::class, $generator);

        $action = $this->app->make(IssueAction::class);

        try {
            $action($enrollment);
            $this->fail('CertificatePdfGenerationException が throw されるはず');
        } catch (CertificatePdfGenerationException) {
            // 期待通り
        }

        $this->assertSame(0, Certificate::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_throws_certificate_pdf_generation_exception_when_generation_fails(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();

        $generator = Mockery::mock(CertificatePdfGenerator::class);
        $generator->shouldReceive('generate')->once()->andThrow(new CertificatePdfGenerationException);
        $this->app->instance(CertificatePdfGenerator::class, $generator);

        $action = $this->app->make(IssueAction::class);

        $this->expectException(CertificatePdfGenerationException::class);

        $action($enrollment);
    }
}
