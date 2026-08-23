<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use App\Services\NotificationRecipientPolicy;
use Illuminate\Support\Facades\DB;

/**
 * 回答投稿ユースケース。
 *
 * スレッド投稿者(自分自身の質問への回答は除く)へ、配信対象の除外規則を通したうえで
 * アプリ内通知 + メール(`QaReplyReceivedNotification`)を afterCommit で発火する。
 *
 * @param array{body: string} $validated QaReply/StoreRequest::rules() で検証済
 */
final class StoreAction
{
    public function __invoke(QaThread $thread, User $author, array $validated): QaReply
    {
        return DB::transaction(function () use ($thread, $author, $validated) {
            $reply = $thread->replies()->create([
                'user_id' => $author->id,
                'body' => $validated['body'],
            ]);

            DB::afterCommit(function () use ($thread, $author, $reply): void {
                $threadAuthor = $thread->user;

                if ($threadAuthor !== null
                    && ! $threadAuthor->is($author)
                    && NotificationRecipientPolicy::eligibleForEventNotification($threadAuthor)) {
                    $threadAuthor->notify(new QaReplyReceivedNotification($reply));
                }
            });

            return $reply;
        });
    }
}
