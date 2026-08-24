<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\UpsertMeetingMemoAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertMeetingMemoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_memo_for_completed_meeting(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        $memo = app(UpsertMeetingMemoAction::class)($meeting, '初回面談メモ');

        $this->assertSame($meeting->id, $memo->meeting_id);
        $this->assertSame('初回面談メモ', $memo->body);
        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '初回面談メモ',
        ]);
    }

    public function test_updates_existing_memo(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();
        app(UpsertMeetingMemoAction::class)($meeting, '最初のメモ');

        app(UpsertMeetingMemoAction::class)($meeting, '更新後のメモ');

        $this->assertDatabaseCount('meeting_memos', 1);
        $this->assertDatabaseHas('meeting_memos', [
            'meeting_id' => $meeting->id,
            'body' => '更新後のメモ',
        ]);
    }

    public function test_throws_when_meeting_is_canceled(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->forStudent($student)->create();

        $this->expectException(MeetingStatusTransitionException::class);

        app(UpsertMeetingMemoAction::class)($meeting, 'メモ');
    }
}
