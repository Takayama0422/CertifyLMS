<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Certification\CertificatePdfGenerationException;
use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Throwable;

/**
 * 修了証 PDF を支給テンプレート(resources/views/certificates/pdf.blade.php)からレンダリングし、
 * private disk(`$certificate->pdf_path`)へ保存するサービス。
 *
 * `Certificate\IssueAction` から DB::transaction 内で呼び出される想定。本サービス自体はロールバックを
 * 行わないため、失敗時に Certificate レコードを残さないことは呼び出し元のトランザクションが担保する。
 * 同様に、PDF 保存後に呼び出し元の transaction 自体が失敗した場合の orphan ファイル削除も
 * 呼び出し元(`IssueAction`)の責務であり、本サービスは行わない。
 *
 * PDF に載せるのは資格名 / 氏名 / 発行日の 3 点のみ(テンプレート側の責務、本サービスは関知しない)。
 */
class CertificatePdfGenerator
{
    /**
     * @throws CertificatePdfGenerationException PDF のレンダリング、または Storage への保存に失敗
     */
    public function generate(Certificate $certificate): void
    {
        try {
            $html = view('certificates.pdf', ['certificate' => $certificate])->render();

            $mpdf = new Mpdf([
                // 日本語(氏名 / 資格名)を埋め込みフォント無しで描画するため Adobe CJK フォントを使用する
                'mode' => 'ja+aCJK',
                'format' => 'A4',
            ]);
            $mpdf->WriteHTML($html);

            $content = $mpdf->Output('', 'S');

            $stored = Storage::disk('private')->put($certificate->pdf_path, $content);

            if ($stored === false) {
                throw new CertificatePdfGenerationException;
            }
        } catch (CertificatePdfGenerationException $e) {
            throw $e;
        } catch (MpdfException|Throwable $e) {
            throw new CertificatePdfGenerationException($e);
        }
    }
}
