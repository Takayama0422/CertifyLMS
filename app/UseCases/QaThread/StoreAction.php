<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 質問スレッド新規投稿ユースケース。常に未解決(open)状態で作成する。
 *
 * @param array{certification_id: string, title: string, body: string} $validated QaThread/StoreRequest::rules() で検証済
 */
final class StoreAction
{
    public function __invoke(User $author, array $validated): QaThread
    {
        return DB::transaction(fn () => QaThread::create([
            'user_id' => $author->id,
            'certification_id' => $validated['certification_id'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => QaThreadStatus::Open->value,
            'resolved_at' => null,
        ]));
    }
}
