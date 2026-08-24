<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\ListCoachMeetingsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListCoachMeetingsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_own_meetings_for_coach(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $own = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $other = Meeting::factory()->reserved()->forCoach($otherCoach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(4)->startOfHour(),
        ]);

        $meetings = app(ListCoachMeetingsAction::class)($coach, 'upcoming', null, null);

        $this->assertTrue($meetings->contains('id', $own->id));
        $this->assertFalse($meetings->contains('id', $other->id));
    }

    public function test_filters_by_student(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();

        $forStudent = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $forOther = Meeting::factory()->reserved()->forCoach($coach)->forStudent($otherStudent)->create([
            'scheduled_at' => now()->addDays(4)->startOfHour(),
        ]);

        $meetings = app(ListCoachMeetingsAction::class)($coach, 'upcoming', $student->id, null);

        $this->assertTrue($meetings->contains('id', $forStudent->id));
        $this->assertFalse($meetings->contains('id', $forOther->id));
    }
}
