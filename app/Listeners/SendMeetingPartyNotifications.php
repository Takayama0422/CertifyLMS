<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Services\NotificationRecipientService;

/**
 * 面談の当事者(受講生 + 担当コーチ)のうち、操作を行っていない側へ、配信対象の除外規則を通したうえで
 * 通知を配信する(PM 指摘により片方向配信に統一)。
 *
 * - 予約確定時: 予約操作は常に受講生本人が行うため、担当コーチのみへ通知する。
 * - キャンセル時: `canceled_by_user_id` を操作者とみなし、操作していない側(相手)のみへ通知する。
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

        $recipient = match (true) {
            $event instanceof MeetingReserved => $meeting->coach,
            $event instanceof MeetingCanceled => $meeting->canceled_by_user_id === $meeting->coach_id
                ? $meeting->student
                : $meeting->coach,
        };

        if ($recipient === null || ! NotificationRecipientService::eligibleForEventNotification($recipient)) {
            return;
        }

        $recipient->notify(match (true) {
            $event instanceof MeetingReserved => new MeetingReservedNotification($meeting),
            $event instanceof MeetingCanceled => new MeetingCanceledNotification($meeting),
        });
    }
}
