<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 回答投稿ユースケース。
 *
 * @param array{body: string} $validated QaReply/StoreRequest::rules() で検証済
 */
final class StoreAction
{
    public function __invoke(QaThread $thread, User $author, array $validated): QaReply
    {
        return DB::transaction(fn () => $thread->replies()->create([
            'user_id' => $author->id,
            'body' => $validated['body'],
        ]));
    }
}
