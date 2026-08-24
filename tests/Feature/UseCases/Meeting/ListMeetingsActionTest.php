<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\ListMeetingsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMeetingsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_own_upcoming_meetings_with_remaining_quota(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $otherStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();

        $own = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => now()->addDays(4)->startOfHour(),
        ]);
        $past = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        $result = app(ListMeetingsAction::class)($student, 'upcoming');

        $this->assertTrue($result['meetings']->contains('id', $own->id));
        $this->assertFalse($result['meetings']->contains('id', $past->id));
        $this->assertSame(3, $result['meetingsRemaining']);
    }

    public function test_past_filter_returns_past_meetings_only(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create();

        $upcoming = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $past = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        $result = app(ListMeetingsAction::class)($student, 'past');

        $this->assertTrue($result['meetings']->contains('id', $past->id));
        $this->assertFalse($result['meetings']->contains('id', $upcoming->id));
    }
}
