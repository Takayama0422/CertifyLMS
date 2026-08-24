<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Events\MeetingCanceled;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\CancelMeetingAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CancelMeetingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancels_reserved_meeting_and_fires_event(): void
    {
        Event::fake();

        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $result = app(CancelMeetingAction::class)($meeting, $student);

        $this->assertSame(MeetingStatus::Canceled, $result->status);
        $this->assertSame($student->id, $result->canceled_by_user_id);
        $this->assertNotNull($result->canceled_at);
        Event::assertDispatched(MeetingCanceled::class, fn (MeetingCanceled $event) => $event->meeting->id === $meeting->id);
    }

    public function test_throws_when_meeting_is_not_reserved(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        $this->expectException(MeetingStatusTransitionException::class);

        app(CancelMeetingAction::class)($meeting, $student);
    }

    public function test_throws_when_meeting_already_started(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subMinutes(10),
        ]);

        $this->expectException(MeetingAlreadyStartedException::class);

        app(CancelMeetingAction::class)($meeting, $student);
    }
}
