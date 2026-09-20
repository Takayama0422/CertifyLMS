<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Enums\MeetingReminderWindow;
use App\Models\Meeting;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 面談リマインダー通知の検証。アプリ内 + メール両チャネル、窓ごとの文言差分、必須データキー。
 */
class MeetingReminderNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_database_and_mail_channels(): void
    {
        $meeting = Meeting::factory()->reserved()->create();
        $notification = new MeetingReminderNotification($meeting, MeetingReminderWindow::Eve);

        $this->assertSame(['database', 'mail'], $notification->via($meeting->student));
    }

    public function test_eve_and_one_hour_before_have_different_titles(): void
    {
        $meeting = Meeting::factory()->reserved()->create();

        $eveTitle = (new MeetingReminderNotification($meeting, MeetingReminderWindow::Eve))->title();
        $oneHourTitle = (new MeetingReminderNotification($meeting, MeetingReminderWindow::OneHourBefore))->title();

        $this->assertNotSame($eveTitle, $oneHourTitle);
    }

    public function test_data_payload_has_meeting_reminder_type_and_required_keys(): void
    {
        $meeting = Meeting::factory()->reserved()->create();
        $data = (new MeetingReminderNotification($meeting, MeetingReminderWindow::Eve))->toArray($meeting->student);

        $this->assertSame('meeting_reminder', $data['notification_type']);
        foreach (['title', 'message', 'body', 'url'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }
}
