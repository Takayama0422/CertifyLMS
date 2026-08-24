<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Events\MeetingReserved;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Meeting\StoreMeetingAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreMeetingActionTest extends TestCase
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

    public function test_creates_reserved_meeting_consumes_quota_and_fires_event(): void
    {
        Event::fake();

        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = Carbon::now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $meeting = app(StoreMeetingAction::class)($enrollment, $scheduledAt, '相談したい');

        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertSame($student->id, $meeting->student_id);
        $this->assertSame($coach->id, $meeting->coach_id);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'amount' => -1,
        ]);
        Event::assertDispatched(MeetingReserved::class, fn (MeetingReserved $event) => $event->meeting->id === $meeting->id);
    }

    public function test_throws_when_quota_is_insufficient(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 0]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = Carbon::now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $this->expectException(InsufficientMeetingQuotaException::class);

        app(StoreMeetingAction::class)($enrollment, $scheduledAt, '相談したい');

        $this->assertDatabaseCount('meetings', 0);
    }
}
