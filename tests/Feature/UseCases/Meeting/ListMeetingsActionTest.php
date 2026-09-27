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

    public function test_all_filter_returns_meetings_regardless_of_status(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create();

        $upcoming = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        $past = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create();

        $result = app(ListMeetingsAction::class)($student, 'all');

        $this->assertTrue($result['meetings']->contains('id', $upcoming->id));
        $this->assertTrue($result['meetings']->contains('id', $past->id));
    }

    /**
     * コードレビュー指摘 7(T-A-02)の回帰テスト。
     * 受講生一覧は upcoming/past/all のいずれでも降順(コーチ一覧のような upcoming 昇順の
     * 非対称は無い)。移設後もこの並び順が保たれていることを検証する。
     */
    public function test_upcoming_filter_orders_meetings_descending_by_scheduled_at(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create();

        $earliest = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(1)->startOfHour(),
        ]);
        $latest = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(5)->startOfHour(),
        ]);
        $middle = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $result = app(ListMeetingsAction::class)($student, 'upcoming');

        $this->assertSame(
            [$latest->id, $middle->id, $earliest->id],
            $result['meetings']->pluck('id')->all(),
            '受講生の upcoming はコーチと異なり降順のはず',
        );
    }

    public function test_past_filter_orders_meetings_descending_by_scheduled_at(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->create();

        $oldest = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(10)->startOfHour(),
        ]);
        $newest = Meeting::factory()->completed()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subDays(1)->startOfHour(),
        ]);

        $result = app(ListMeetingsAction::class)($student, 'past');

        $this->assertSame(
            [$newest->id, $oldest->id],
            $result['meetings']->pluck('id')->all(),
        );
    }
}
