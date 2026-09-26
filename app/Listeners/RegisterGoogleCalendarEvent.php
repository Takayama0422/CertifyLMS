<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MeetingReserved;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * 面談の予約成立時、担当コーチが Google カレンダー連携済みなら予定を自動登録する(S-A-01)。
 *
 * `MeetingReserved` は予約確定と同一の DB トランザクション内で発火されるため、`ShouldHandleEventsAfterCommit`
 * でコミット後に実行する。Google 通信をトランザクション内に置くと、通信が終わるまでロックを保持し、かつ
 * 予約が巻き戻った場合に外部へ登録済みの予定だけが残る。失敗は `GoogleCalendarService` 内で握りつぶされるため、
 * 予約の成立には影響しない(未連携のコーチには何もしない)。
 */
final class RegisterGoogleCalendarEvent implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    public function handle(MeetingReserved $event): void
    {
        $this->googleCalendar->registerMeeting($event->meeting);
    }
}
