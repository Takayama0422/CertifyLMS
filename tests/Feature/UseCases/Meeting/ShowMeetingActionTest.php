<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\ShowMeetingAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowMeetingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_relations_needed_for_detail_view(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create();

        $result = app(ShowMeetingAction::class)($meeting);

        $this->assertTrue($result->relationLoaded('enrollment'));
        $this->assertTrue($result->relationLoaded('coach'));
        $this->assertTrue($result->relationLoaded('student'));
        $this->assertTrue($result->relationLoaded('canceledBy'));
        $this->assertTrue($result->relationLoaded('meetingMemo'));
        $this->assertSame($meeting->id, $result->id);
    }
}
