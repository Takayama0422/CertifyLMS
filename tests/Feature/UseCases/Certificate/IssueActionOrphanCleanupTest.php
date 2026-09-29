<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Certificate;

use App\Exceptions\Certification\CertificatePdfGenerationException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfGenerator;
use App\UseCases\Certificate\IssueAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * PDF の Storage への書き込みが成功した後に transaction 全体が失敗(ROLLBACK)した場合でも、
 * 書き込み済みの PDF ファイルが orphan として残らないことを検証する
 * (`SectionImage\StoreAction` と同じ作法: DB::transaction を try/catch で包み、
 * catch 節で保存済みファイルを削除する)。
 *
 * `tests/Feature/UseCases/Certificate/IssueActionPdfGenerationFailureTest.php` は
 * 「Certificate レコードが残らないこと」を検証する別テストであり、本テストは
 * 「PDF 実体ファイルが残らないこと」に焦点を当てるため別ファイルとして追加する
 * (既存テストは変更しない)。
 */
class IssueActionOrphanCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_orphan_pdf_file_is_removed_when_transaction_fails_after_pdf_is_written(): void
    {
        Storage::fake('private');

        $enrollment = Enrollment::factory()->passed()->create();

        // generate() が Storage への書き込みには成功したが、その後(commit 失敗等)で
        // transaction 全体が失敗するケースを再現する。
        $writtenPath = null;
        $generator = Mockery::mock(CertificatePdfGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (Certificate $certificate) use (&$writtenPath) {
                $writtenPath = $certificate->pdf_path;
                Storage::disk('private')->put($writtenPath, '%PDF-1.7 dummy content');
                throw new CertificatePdfGenerationException;
            });
        $this->app->instance(CertificatePdfGenerator::class, $generator);

        $action = $this->app->make(IssueAction::class);

        try {
            $action($enrollment);
            $this->fail('CertificatePdfGenerationException が throw されるはず');
        } catch (CertificatePdfGenerationException) {
            // 期待通り
        }

        $this->assertNotNull($writtenPath, '事前条件: generate() 内で PDF が書き込まれていること');
        $this->assertSame(0, Certificate::query()->count());
        Storage::disk('private')->assertMissing($writtenPath);
    }
}
