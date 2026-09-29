<?php

declare(strict_types=1);

namespace App\Exceptions\Certification;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 修了証 PDF の生成・保存に失敗した場合に throw される。
 * `Certificate\IssueAction` の DB::transaction 内から throw することで、呼び出し元のトランザクションが
 * ROLLBACK し、Certificate レコードが残らない(修了証は未発行のまま)ことを保証する。
 */
final class CertificatePdfGenerationException extends HttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(500, '修了証 PDF の生成に失敗しました。時間をおいて再度お試しください。', $previous);
    }
}
