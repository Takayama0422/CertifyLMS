<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use Illuminate\Support\Facades\DB;

/**
 * 回答更新ユースケース。
 *
 * @param array{body: string} $validated QaReply/UpdateRequest::rules() で検証済
 */
final class UpdateAction
{
    public function __invoke(QaReply $reply, array $validated): QaReply
    {
        return DB::transaction(function () use ($reply, $validated) {
            $reply->update(['body' => $validated['body']]);

            return $reply->fresh();
        });
    }
}
