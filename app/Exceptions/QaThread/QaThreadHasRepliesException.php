<?php

declare(strict_types=1);

namespace App\Exceptions\QaThread;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 投稿者本人によるスレッド削除で、配下に回答が 1 件以上存在する場合に throw される(HTTP 409)。
 * 回答 0 件のときのみ自己削除できる(確定仕様。管理者モデレーション削除にはこの制約を適用しない)。
 */
final class QaThreadHasRepliesException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('回答が付いている質問は削除できません。', $previous);
    }
}
