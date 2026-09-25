<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 修了証 PDF のダウンロード Controller。
 *
 * 認可は CertificatePolicy::download(本人 / 担当コーチ / 管理者(全件)) で完結する。
 * Enrollment / User のステータスは問わない(修了証は永続資産)。
 * PDF ファイルが private disk に存在しない場合はダウンロード不可(404)。
 */
class DownloadCertificateController extends Controller
{
    public function show(Certificate $certificate): StreamedResponse
    {
        $this->authorize('download', $certificate);

        if (! Storage::disk('private')->exists($certificate->pdf_path)) {
            abort(404);
        }

        return Storage::disk('private')->download(
            $certificate->pdf_path,
            $certificate->certification->name.'_修了証.pdf',
        );
    }
}
