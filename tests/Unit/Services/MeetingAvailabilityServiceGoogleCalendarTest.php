<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\GoogleCalendarCredential;
use App\Models\User;
use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FakeGoogleCalendarClient;
use Tests\TestCase;

/**
 * S-A-01: MeetingAvailabilityService への Google カレンダー連携反映を検証する。
 *
 * 既存の `tests/Unit/Services/MeetingAvailabilityServiceTest.php`(既存テストは書き換え禁止)を
 * 補完する形で、別ファイルとして追加する。実通信は `FakeGoogleCalendarClient` で完全に置き換える。
 */
class MeetingAvailabilityServiceGoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    private function fake(): FakeGoogleCalendarClient
    {
        $fake = new FakeGoogleCalendarClient;
        $this->app->instance(GoogleCalendarClient::class, $fake);

        return $fake;
    }

    public function test_excludes_slot_when_connected_coach_has_google_busy_interval(): void
    {
        $fake = $this->fake();
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);

        $credential = GoogleCalendarCredential::factory()->forCoach($coach)->create(['access_token' => 'tok-a']);
        $fake->busyIntervalsByToken['tok-a'] = [[
            'start' => Carbon::parse('2026-06-01 10:00:00'),
            'end' => Carbon::parse('2026-06-01 11:00:00'),
        ]];

        $date = Carbon::parse('2026-06-01'); // Monday
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '12:00:00')->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $times = $slots->map(fn (array $s) => $s['slot_start']->format('H:i'))->all();
        $this->assertEquals(['09:00', '11:00'], $times, '10:00 は Google 側予定と重なるため空き枠から除外される');
    }

    public function test_unconnected_coach_is_unaffected_by_google_calendar(): void
    {
        $this->fake();
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        // Google 連携なし(GoogleCalendarCredential を作らない)

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '11:00:00')->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        // 未連携コーチは従来通りの空き判定(2 枠とも空き)
        $this->assertCount(2, $slots);
    }

    public function test_partial_exclusion_when_only_one_of_two_coaches_is_busy(): void
    {
        $fake = $this->fake();
        $certification = Certification::factory()->published()->create();
        $coachA = User::factory()->coach()->create();
        $coachB = User::factory()->coach()->create();
        $this->attachCoach($certification, $coachA);
        $this->attachCoach($certification, $coachB);

        GoogleCalendarCredential::factory()->forCoach($coachA)->create(['access_token' => 'tok-a']);
        $fake->busyIntervalsByToken['tok-a'] = [[
            'start' => Carbon::parse('2026-06-01 09:00:00'),
            'end' => Carbon::parse('2026-06-01 10:00:00'),
        ]];
        // coachB は未連携のまま

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coachA)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();
        CoachAvailability::factory()->forCoach($coachB)->onDay(1)->timeRange('09:00:00', '10:00:00')->create();

        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        // coachA は Google 側予定で不可、coachB は空きのため available_coach_count = 1 のまま枠は残る
        $this->assertCount(1, $slots);
        $this->assertSame(1, $slots->first()['available_coach_count']);
    }

    public function test_google_communication_failure_falls_back_to_normal_availability(): void
    {
        $fake = $this->fake();
        $fake->busyIntervalsException = new RuntimeException('timeout');
        $certification = Certification::factory()->published()->create();
        $coach = User::factory()->coach()->create();
        $this->attachCoach($certification, $coach);
        GoogleCalendarCredential::factory()->forCoach($coach)->create();

        $date = Carbon::parse('2026-06-01');
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '11:00:00')->create();

        // Google 通信が失敗しても例外を投げず、通常の空き判定(2 枠とも空き)にフォールバックすること
        $slots = app(MeetingAvailabilityService::class)->slotsForCertification($certification, $date);

        $this->assertCount(2, $slots);
    }
}
