<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MeetingCanceled;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * 面談のキャンセル時、Google カレンダーに登録済みの予定があれば連動して削除する(S-A-01)。
 *
 * `MeetingCanceled` はキャンセル確定と同一の DB トランザクション内で発火されるため、
 * `ShouldHandleEventsAfterCommit` でコミット後に実行する(`RegisterGoogleCalendarEvent` と同じ理由)。
 * 未連携 / 未登録 / 通信失敗は `GoogleCalendarService` 内で握りつぶされ、キャンセルの成立には影響しない。
 */
final class DeleteGoogleCalendarEvent implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    public function handle(MeetingCanceled $event): void
    {
        $this->googleCalendar->cancelMeeting($event->meeting);
    }
}
