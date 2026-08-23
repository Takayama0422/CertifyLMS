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
 * `BusinessEventNotification` 系はキュー非同期化のスコープ外(S-B-04 の方針)のため、本リスナーも
 * `ShouldQueue` を実装せず、イベント発火と同一プロセス内で同期実行する。
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
