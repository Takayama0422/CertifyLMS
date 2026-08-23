<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Notification;

use App\Enums\MeetingReminderWindow;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * 面談リマインダー配信ユースケースの検証。
 * 観点: 予約済み面談のみ対象 / 前日・1 時間前それぞれの窓の境界 / 当事者双方への配信 /
 * 配信対象外の除外 / **同じ窓を 2 回実行しても二重配信しないこと(最重要)**。
 */
class SendMeetingRemindersActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): SendMeetingRemindersAction
    {
        return app(SendMeetingRemindersAction::class);
    }

    public function test_eve_window_targets_meetings_scheduled_for_tomorrow(): void
    {
        $tomorrow = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);
        $dayAfterTomorrow = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDays(2)->setTime(15, 0)]);
        $today = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addHours(3)]);

        $count = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $tomorrow->student_id, 'type' => MeetingReminderNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $tomorrow->coach_id, 'type' => MeetingReminderNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $dayAfterTomorrow->student_id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $today->student_id]);
    }

    public function test_one_hour_before_window_targets_meetings_starting_soon(): void
    {
        $soon = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addHour()]);
        $farLater = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addHours(3)]);
        $tooSoon = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addMinutes(5)]);

        $count = $this->action()(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $soon->student_id, 'type' => MeetingReminderNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $farLater->student_id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $tooSoon->student_id]);
    }

    public function test_canceled_and_completed_meetings_are_excluded(): void
    {
        $canceled = Meeting::factory()->canceled()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);
        $completed = Meeting::factory()->completed()->create();

        $count = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $canceled->student_id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $completed->student_id]);
    }

    public function test_withdrawn_party_is_excluded_but_other_party_still_notified(): void
    {
        $withdrawnCoach = User::factory()->coach()->withdrawn()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($withdrawnCoach)->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);

        $count = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $withdrawnCoach->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $meeting->student_id]);
    }

    public function test_running_the_same_window_twice_does_not_send_duplicate_notifications(): void
    {
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);

        $first = $this->action()(MeetingReminderWindow::Eve);
        $second = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(2, $first);
        $this->assertSame(0, $second, '2 回目の実行では二重配信されず 0 件であるべき');
        $this->assertDatabaseCount('meeting_reminder_dispatches', 2);
        $this->assertSame(
            2,
            DatabaseNotification::where('type', MeetingReminderNotification::class)
                ->whereIn('notifiable_id', [$meeting->student_id, $meeting->coach_id])
                ->count(),
        );
    }

    public function test_eve_and_one_hour_before_dispatch_records_are_scoped_independently(): void
    {
        $eveMeeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);
        $soonMeeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addHour()]);

        $eveCount = $this->action()(MeetingReminderWindow::Eve);
        $oneHourCount = $this->action()(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(2, $eveCount);
        $this->assertSame(2, $oneHourCount);
        $this->assertDatabaseHas('meeting_reminder_dispatches', ['meeting_id' => $eveMeeting->id, 'window' => 'eve']);
        $this->assertDatabaseHas('meeting_reminder_dispatches', ['meeting_id' => $soonMeeting->id, 'window' => 'one_hour_before']);
        $this->assertDatabaseMissing('meeting_reminder_dispatches', ['meeting_id' => $eveMeeting->id, 'window' => 'one_hour_before']);
        $this->assertDatabaseCount('meeting_reminder_dispatches', 4);

        // 再実行しても両窓とも増えない
        $this->assertSame(0, $this->action()(MeetingReminderWindow::Eve));
        $this->assertSame(0, $this->action()(MeetingReminderWindow::OneHourBefore));
        $this->assertDatabaseCount('meeting_reminder_dispatches', 4);
    }
}
