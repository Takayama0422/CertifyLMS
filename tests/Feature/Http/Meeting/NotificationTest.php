<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 面談予約(`POST .../meetings`)/ キャンセル(`POST /meetings/{meeting}/cancel`)が
 * 当事者(受講生 + 担当コーチ)双方へ `meeting_reserved` / `meeting_canceled` 通知を発火することを検証する。
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    public function test_both_parties_are_notified_when_meeting_is_reserved(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $student->id,
            'type' => MeetingReservedNotification::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $coach->id,
            'type' => MeetingReservedNotification::class,
        ]);
    }

    public function test_both_parties_are_notified_when_meeting_is_canceled(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $student->id,
            'type' => MeetingCanceledNotification::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $coach->id,
            'type' => MeetingCanceledNotification::class,
        ]);
    }

    public function test_withdrawn_coach_is_excluded_from_cancellation_notification(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->withdrawn()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $coach->id]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $student->id,
            'type' => MeetingCanceledNotification::class,
        ]);
    }
}
