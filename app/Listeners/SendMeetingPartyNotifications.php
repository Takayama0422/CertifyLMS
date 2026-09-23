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
 * `BusinessEventNotification` 系はキュー非同期化のスコープ外(S-B-04 の方針)のため、本リスナーも
 * `ShouldQueue` を実装せず、イベント発火と同一プロセス内で同期実行する。
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
