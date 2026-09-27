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

    public function test_filters_by_enrollment(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $forEnrollment = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $forOtherEnrollment = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(4)->startOfHour(),
        ]);

        $meetings = app(ListCoachMeetingsAction::class)($coach, 'upcoming', null, $forEnrollment->enrollment_id);

        $this->assertTrue($meetings->contains('id', $forEnrollment->id));
        $this->assertFalse($meetings->contains('id', $forOtherEnrollment->id));
    }

    /**
     * コードレビュー指摘 7(T-A-02)の回帰テスト。
     * コーチ一覧は「今後の予定(upcoming)」のみ昇順、それ以外(past/all)は降順という非対称な仕様
     * (`ListCoachMeetingsAction` のクラス docblock 参照)。移設後もこの並び順が保たれていることを検証する。
     */
    public function test_upcoming_filter_orders_meetings_ascending_by_scheduled_at(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $latest = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(5)->startOfHour(),
        ]);
        $earliest = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(1)->startOfHour(),
        ]);
        $middle = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $meetings = app(ListCoachMeetingsAction::class)($coach, 'upcoming', null, null);

        $this->assertSame(
            [$earliest->id, $middle->id, $latest->id],
            $meetings->pluck('id')->all(),
            'upcoming は直近の予定を先頭にする昇順のはず',
        );
    }

    public function test_past_filter_orders_meetings_descending_by_scheduled_at(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $oldest = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(10)->startOfHour(),
        ]);
        $newest = Meeting::factory()->canceled()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(1)->startOfHour(),
        ]);
        $middle = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(5)->startOfHour(),
        ]);
        $upcoming = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $meetings = app(ListCoachMeetingsAction::class)($coach, 'past', null, null);

        $this->assertSame(
            [$newest->id, $middle->id, $oldest->id],
            $meetings->pluck('id')->all(),
            'past は直近の活動を先頭にする降順のはず',
        );
        $this->assertFalse($meetings->contains('id', $upcoming->id), 'past に reserved(未来)が混ざってはならない');
    }

    public function test_all_filter_orders_meetings_descending_by_scheduled_at_across_statuses(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();

        $past = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(5)->startOfHour(),
        ]);
        $future = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(2)->startOfHour(),
        ]);

        $meetings = app(ListCoachMeetingsAction::class)($coach, 'all', null, null);

        $this->assertSame(
            [$future->id, $past->id],
            $meetings->pluck('id')->all(),
            'all は status を問わず scheduled_at 降順のはず',
        );
    }
}
