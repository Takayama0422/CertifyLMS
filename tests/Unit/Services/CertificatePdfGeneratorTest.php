<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certificate;
use App\Services\CertificatePdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 修了証 PDF の実体生成(mpdf レンダリング + private disk 保存)を検証する。
 * 日本語(氏名 / 資格名)を含むテンプレートを実際にレンダリングし、PDF バイナリが
 * `pdf_path` へ保存されることを確認する(mpdf 自体はモックしない)。
 */
class CertificatePdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_pdf_file_at_certificate_pdf_path(): void
    {
        Storage::fake('private');

        $certificate = Certificate::factory()->create([
            'pdf_path' => 'certificates/test-ulid.pdf',
        ]);

        (new CertificatePdfGenerator)->generate($certificate);

        Storage::disk('private')->assertExists($certificate->pdf_path);
        $content = Storage::disk('private')->get($certificate->pdf_path);
        $this->assertStringStartsWith('%PDF', $content);
    }
}
