<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Exceptions\QaThread\QaThreadHasRepliesException;
use App\Models\QaThread;
use Illuminate\Support\Facades\DB;

/**
 * 投稿者本人によるスレッド削除ユースケース。回答が 1 件でも付いていれば削除できない(確定仕様)。
 * 管理者モデレーション削除(回答件数を問わない)は `AdminDestroyAction` を使う。
 *
 * @throws QaThreadHasRepliesException 配下に回答が 1 件以上存在する場合
 */
final class DestroyAction
{
    public function __invoke(QaThread $thread): void
    {
        if ($thread->loadCount('replies')->replies_count > 0) {
            throw new QaThreadHasRepliesException;
        }

        DB::transaction(fn () => $thread->delete());
    }
}
