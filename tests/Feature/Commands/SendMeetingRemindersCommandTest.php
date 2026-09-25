<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Meeting;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * `php artisan notifications:send-meeting-reminders` コマンドの検証。
 * 観点: --window 未指定 / 不正値は失敗して終了する / 正常系は配信して成功終了する /
 * コマンドを 2 回実行しても二重配信しない(HTTP ではなくコマンド経路での確認)。
 */
class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_when_window_option_is_missing(): void
    {
        $exitCode = $this->artisan('notifications:send-meeting-reminders')->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_fails_when_window_option_is_invalid(): void
    {
        $exitCode = $this->artisan('notifications:send-meeting-reminders', ['--window' => 'nonsense'])->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_eve_window_succeeds_and_dispatches_reminders(): void
    {
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);

        $exitCode = $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])->run();

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $meeting->student_id,
            'type' => MeetingReminderNotification::class,
        ]);
    }

    public function test_running_command_twice_does_not_duplicate_notifications(): void
    {
        Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])->run();
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])->run();

        $this->assertSame(2, DatabaseNotification::where('type', MeetingReminderNotification::class)->count());
    }
}
