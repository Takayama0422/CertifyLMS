<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;
use Illuminate\Support\Facades\DB;

/**
 * 質問スレッド更新ユースケース(タイトル / 本文のみ、資格は変更不可)。
 *
 * @param array{title: string, body: string} $validated QaThread/UpdateRequest::rules() で検証済
 */
final class UpdateAction
{
    public function __invoke(QaThread $thread, array $validated): QaThread
    {
        return DB::transaction(function () use ($thread, $validated) {
            $thread->update([
                'title' => $validated['title'],
                'body' => $validated['body'],
            ]);

            return $thread->fresh();
        });
    }
}
