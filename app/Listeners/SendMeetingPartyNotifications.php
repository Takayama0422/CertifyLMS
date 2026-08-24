<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Services\NotificationRecipientService;

/**
 * 面談の当事者(受講生 + 担当コーチ)へ、配信対象の除外規則を通したうえで通知を配信する。
 *
 * `MeetingReserved` / `MeetingCanceled` イベントを受けて動く(発火元は Controller に限らない)。
 * 本リスナー自体は `ShouldQueue` を実装せずイベント発火と同一プロセス内で同期実行するが、
 * `$recipient->notify()` が呼ぶ `BusinessEventNotification` 系は T-A-05 で `ShouldQueueAfterCommit`
 * を実装したため、実際の配信(database 書き込み + mail 送信)はバックグラウンドのキューへ委譲される。
 * イベント発火自体は Controller 側で `DB::transaction()` の外(commit 後)に行われるため、
 * ここでの `notify()` 呼び出し時点でトランザクションは既に終わっており、即座にキューへ積まれる。
 */
final class SendMeetingPartyNotifications
{
    public function handle(MeetingReserved|MeetingCanceled $event): void
    {
        $meeting = $event->meeting;
        $meeting->loadMissing(['student', 'coach']);

        foreach ([$meeting->student, $meeting->coach] as $recipient) {
            if ($recipient === null || ! NotificationRecipientService::eligibleForEventNotification($recipient)) {
                continue;
            }

            $recipient->notify(match (true) {
                $event instanceof MeetingReserved => new MeetingReservedNotification($meeting),
                $event instanceof MeetingCanceled => new MeetingCanceledNotification($meeting),
            });
        }
    }
}
