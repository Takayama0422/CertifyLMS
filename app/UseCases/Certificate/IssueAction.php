<?php

declare(strict_types=1);

namespace App\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateAlreadyIssuedException;
use App\Exceptions\Certification\CertificatePdfGenerationException;
use App\Exceptions\Certification\EnrollmentNotPassedException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * 修了証を発行するユースケース。受講生自己発火型の修了処理 `\App\UseCases\Enrollment\ReceiveCertificateAction` から呼び出される。
 *
 * 業務分岐:
 * - Enrollment が `status=passed` + `passed_at != null` でない: EnrollmentNotPassedException（409）
 * - 同一 Enrollment に対する二重呼出: CertificateAlreadyIssuedException（409、事前 lockForUpdate + exists で検出）
 *
 * 修了証レコードの INSERT + PDF 実体生成(`CertificatePdfGenerator`)は同一 `DB::transaction()` 内で実行する。
 * PDF 生成が失敗した場合は例外が transaction を ROLLBACK させるため、Certificate レコードは残らない
 * (修了証は未発行のまま保たれる)。
 *
 * `pdf_path` は Storage 保存(PDF 実体の書き込み)より前、DB トランザクションの外側で確定させる。
 * `SectionImage\StoreAction` と同じ作法で DB::transaction 全体を try/catch し、
 * PDF 保存後に何らかの理由で transaction が失敗(commit 失敗含む)した場合は catch 節で
 * 保存済み PDF を削除し、どのレコードからも参照されない orphan ファイルを残さない
 * (`generate()` 自体が失敗した場合、ファイルは書き込まれていないため delete は no-op)。
 */
final class IssueAction
{
    public function __construct(
        private readonly CertificatePdfGenerator $pdfGenerator,
    ) {}

    /**
     * @throws EnrollmentNotPassedException 受講登録が修了状態ではない
     * @throws CertificateAlreadyIssuedException 同一 Enrollment で修了証が既発行
     * @throws CertificatePdfGenerationException PDF の生成・保存に失敗
     */
    public function __invoke(Enrollment $enrollment): Certificate
    {
        if ($enrollment->status !== EnrollmentStatus::Passed || $enrollment->passed_at === null) {
            throw new EnrollmentNotPassedException;
        }

        $pdfPath = 'certificates/'.Str::ulid().'.pdf';

        try {
            return DB::transaction(function () use ($enrollment, $pdfPath) {
                // 二重発行ガード: lockForUpdate で同時呼出を直列化し、enrollment_id UNIQUE 違反を例外メッセージ判別ではなく事前 SELECT で確定検出する
                $existing = Certificate::query()
                    ->where('enrollment_id', $enrollment->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw new CertificateAlreadyIssuedException;
                }

                $certificate = Certificate::create([
                    'user_id' => $enrollment->user_id,
                    'enrollment_id' => $enrollment->id,
                    'certification_id' => $enrollment->certification_id,
                    'pdf_path' => $pdfPath,
                    'issued_at' => now(),
                ]);

                $this->pdfGenerator->generate($certificate);

                return $certificate;
            });
        } catch (Throwable $e) {
            Storage::disk('private')->delete($pdfPath);
            throw $e;
        }
    }
}
