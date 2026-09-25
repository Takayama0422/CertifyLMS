<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;
use Illuminate\Support\Facades\DB;

/**
 * 管理者モデレーションによるスレッド削除ユースケース。回答件数に関わらず削除できる(配下回答は
 * `qa_replies.qa_thread_id` の cascadeOnDelete で道連れ削除される)。投稿者本人の自己削除
 * (`DestroyAction`、回答 0 件のみ許可)とは業務規則が異なるため別クラスに分ける。
 */
final class AdminDestroyAction
{
    public function __invoke(QaThread $thread): void
    {
        DB::transaction(fn () => $thread->delete());
    }
}
