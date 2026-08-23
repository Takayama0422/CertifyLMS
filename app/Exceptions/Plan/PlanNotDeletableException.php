<?php

declare(strict_types=1);

namespace App\Exceptions\Plan;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 削除条件を満たさないプランを削除しようとした際の例外（HTTP 409）。
 * `Plan\DestroyAction` が「下書きかつ受講者未紐づきの場合のみ削除可能」のドメインルールから throw する。
 */
final class PlanNotDeletableException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('下書きかつ受講者が紐づいていないプランのみ削除できます。', $previous);
    }
}
