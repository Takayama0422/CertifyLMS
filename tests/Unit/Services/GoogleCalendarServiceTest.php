<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\GoogleCalendar\GoogleCalendarStateMismatchException;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\GoogleCalendar\DataTransfer\GoogleOAuthToken;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeGoogleCalendarClient;
use Tests\TestCase;

/**
 * S-A-01: GoogleCalendarService の単体テスト。
 *
 * `GoogleCalendarClient` は実通信を行わない `FakeGoogleCalendarClient` に差し替え、実通信を一切発生させない。
 * 「Google との通信に失敗しても面談機能の根幹は止めない」フォールバック挙動と、
 * OAuth state 検証(なりすまし拒否)、トークンの自動更新(連携の継続)を検証する。
 */
class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeGoogleCalendarClient
    {
        $fake = new FakeGoogleCalendarClient;
        $this->app->instance(GoogleCalendarClient::class, $fake);

        return $fake;
    }

    private function service(): GoogleCalendarService
    {
        return $this->app->make(GoogleCalendarService::class);
    }

    public function test_busy_intervals_returns_empty_when_coach_not_connected(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();

        $result = $this->service()->busyIntervals($coach, now(), now()->addDay());

        $this->assertTrue($result->isEmpty());
    }

    public function test_busy_intervals_returns_intervals_when_connected(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();
        $credential = GoogleCalendarCredential::factory()->forCoach($coach)->create([
            'access_token' => 'token-abc',
        ]);
        $busyStart = Carbon::parse('2026-06-01 10:00:00');
        $busyEnd = Carbon::parse('2026-06-01 11:00:00');
        $fake->busyIntervalsByToken['token-abc'] = [['start' => $busyStart, 'end' => $busyEnd]];

        $result = $this->service()->busyIntervals($coach, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()['start']->equalTo($busyStart));
    }

    public function test_busy_intervals_falls_back_to_empty_when_client_throws(): void
    {
        $fake = $this->fake();
        $fake->busyIntervalsException = new RuntimeException('network down');
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();

        $result = $this->service()->busyIntervals($coach, now(), now()->addDay());

        $this->assertTrue($result->isEmpty());
    }

    /**
     * 「応答が返ってこない失敗」(タイムアウト)を模した回帰テスト。
     *
     * 実装(GoogleApiCalendarClient)には接続 / 応答タイムアウトを設定しており、Google が無応答のときは
     * 有限時間で必ず例外として打ち切られる想定になった。ここでは実通信を経由せず(FakeGoogleCalendarClient
     * が模擬的に usleep で遅延させてから例外を投げる)、「即時の例外」だけでなく「遅延したうえでの例外」でも
     * GoogleCalendarService のフォールバックが同様に働き、空 Collection を返して例外を外へ漏らさないことを検証する。
     */
    public function test_busy_intervals_falls_back_to_empty_when_client_throws_after_delay(): void
    {
        $fake = $this->fake();
        $fake->delayMicroseconds = 50_000; // 50ms 遅延させてから例外を投げる(タイムアウトの模擬)
        $fake->busyIntervalsException = new RuntimeException('cURL error 28: Operation timed out');
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();

        $startedAt = microtime(true);
        $result = $this->service()->busyIntervals($coach, now(), now()->addDay());
        $elapsedSeconds = microtime(true) - $startedAt;

        $this->assertTrue($result->isEmpty());
        // 遅延を経由したこと自体の確認(0 秒で即時に例外化されたのではないことを保証する)
        $this->assertGreaterThanOrEqual(0.05, $elapsedSeconds);
    }

    /**
     * hasConflict も busyIntervals 経由でフォールバックするため、遅延した失敗で false(空きとして扱う)に
     * なることを検証する(受講生の予約画面がタイムアウトで止まらないことの回帰確認)。
     */
    public function test_has_conflict_false_when_client_throws_after_delay(): void
    {
        $fake = $this->fake();
        $fake->delayMicroseconds = 50_000;
        $fake->busyIntervalsException = new RuntimeException('cURL error 28: Operation timed out');
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();

        $hasConflict = $this->service()->hasConflict($coach, now(), now()->addHour());

        $this->assertFalse($hasConflict);
    }

    public function test_has_conflict_true_when_overlapping(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create(['access_token' => 'tok']);
        $fake->busyIntervalsByToken['tok'] = [[
            'start' => Carbon::parse('2026-06-01 10:00:00'),
            'end' => Carbon::parse('2026-06-01 11:00:00'),
        ]];

        $hasConflict = $this->service()->hasConflict(
            $coach,
            Carbon::parse('2026-06-01 10:30:00'),
            Carbon::parse('2026-06-01 11:30:00'),
        );

        $this->assertTrue($hasConflict);
    }

    public function test_has_conflict_false_when_not_overlapping(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create(['access_token' => 'tok']);
        $fake->busyIntervalsByToken['tok'] = [[
            'start' => Carbon::parse('2026-06-01 10:00:00'),
            'end' => Carbon::parse('2026-06-01 11:00:00'),
        ]];

        $hasConflict = $this->service()->hasConflict(
            $coach,
            Carbon::parse('2026-06-01 11:00:00'),
            Carbon::parse('2026-06-01 12:00:00'),
        );

        $this->assertFalse($hasConflict);
    }

    public function test_register_meeting_noop_when_coach_not_connected(): void
    {
        $fake = $this->fake();
        $meeting = Meeting::factory()->reserved()->create();

        $this->service()->registerMeeting($meeting);

        $this->assertCount(0, $fake->createdEvents);
        $this->assertNull($meeting->fresh()->google_calendar_event_id);
    }

    public function test_register_meeting_stores_event_id_when_connected(): void
    {
        $fake = $this->fake();
        $fake->nextEventId = 'evt_123';
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->create();

        $this->service()->registerMeeting($meeting);

        $this->assertCount(1, $fake->createdEvents);
        $this->assertSame('evt_123', $meeting->fresh()->google_calendar_event_id);
    }

    public function test_register_meeting_swallows_client_exception(): void
    {
        $fake = $this->fake();
        $fake->createEventException = new RuntimeException('quota exceeded');
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->create();

        // 例外を投げず正常終了すること(面談予約の成立自体は失敗させない)
        $this->service()->registerMeeting($meeting);

        $this->assertNull($meeting->fresh()->google_calendar_event_id);
    }

    public function test_cancel_meeting_deletes_event_and_clears_id(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->create([
            'google_calendar_event_id' => 'evt_to_delete',
        ]);

        $this->service()->cancelMeeting($meeting);

        $this->assertCount(1, $fake->deletedEvents);
        $this->assertSame('evt_to_delete', $fake->deletedEvents[0]['eventId']);
        $this->assertNull($meeting->fresh()->google_calendar_event_id);
    }

    public function test_cancel_meeting_noop_when_no_event_registered(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->create([
            'google_calendar_event_id' => null,
        ]);

        $this->service()->cancelMeeting($meeting);

        $this->assertCount(0, $fake->deletedEvents);
    }

    public function test_cancel_meeting_swallows_client_exception_and_keeps_event_id(): void
    {
        $fake = $this->fake();
        $fake->deleteEventException = new RuntimeException('temporarily unavailable');
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->create([
            'google_calendar_event_id' => 'evt_still_there',
        ]);

        $this->service()->cancelMeeting($meeting);

        $this->assertSame('evt_still_there', $meeting->fresh()->google_calendar_event_id);
    }

    public function test_authorization_url_stores_state_in_session_and_returns_client_url(): void
    {
        $fake = $this->fake();
        $fake->authorizationUrlResult = 'https://accounts.google.com/o/oauth2/v2/auth?foo=bar';
        $coach = User::factory()->coach()->create();

        $url = $this->service()->authorizationUrl($coach, 'https://app.test/callback', '/settings/availability');

        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth?foo=bar', $url);
        $this->assertCount(1, $fake->authorizationUrlCalls);
        $this->assertSame('https://app.test/callback', $fake->authorizationUrlCalls[0]['redirectUri']);
        $this->assertNotEmpty(session('google_calendar.oauth_state'));
        $this->assertSame($coach->id, session('google_calendar.oauth_user_id'));
        $this->assertSame('/settings/availability', session('google_calendar.oauth_redirect_path'));
    }

    public function test_connect_succeeds_with_matching_state(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();
        $this->service()->authorizationUrl($coach, 'https://app.test/callback', '/settings/availability');
        $state = session('google_calendar.oauth_state');

        $credential = $this->service()->connect($coach, 'auth-code', $state, 'https://app.test/callback');

        $this->assertInstanceOf(GoogleCalendarCredential::class, $credential);
        $this->assertSame($coach->id, $credential->user_id);
        $this->assertSame('primary', $credential->calendar_id);
        $this->assertDatabaseHas('google_calendar_credentials', ['user_id' => $coach->id]);
        // なりすまし対策の state はコールバック後に使い捨てる
        $this->assertNull(session('google_calendar.oauth_state'));
    }

    public function test_connect_rejects_mismatched_state(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();
        $this->service()->authorizationUrl($coach, 'https://app.test/callback', '/settings/availability');

        $this->expectException(GoogleCalendarStateMismatchException::class);

        $this->service()->connect($coach, 'auth-code', 'wrong-state', 'https://app.test/callback');
    }

    public function test_connect_rejects_when_session_belongs_to_different_user(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();
        $impersonator = User::factory()->coach()->create();
        $this->service()->authorizationUrl($coach, 'https://app.test/callback', '/settings/availability');
        $state = session('google_calendar.oauth_state');

        $this->expectException(GoogleCalendarStateMismatchException::class);

        // セッションは $coach の認可フローで発行されたものだが、別コーチ本人として callback を叩いたケース
        $this->service()->connect($impersonator, 'auth-code', $state, 'https://app.test/callback');
    }

    public function test_connect_rejects_without_prior_authorization_url_call(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();

        $this->expectException(GoogleCalendarStateMismatchException::class);

        $this->service()->connect($coach, 'auth-code', 'any-state', 'https://app.test/callback');
    }

    public function test_disconnect_removes_credential(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();

        $this->service()->disconnect($coach);

        $this->assertDatabaseMissing('google_calendar_credentials', ['user_id' => $coach->id]);
        $this->assertFalse($this->service()->isConnected($coach->fresh()));
    }

    public function test_busy_intervals_refreshes_expired_access_token(): void
    {
        $fake = $this->fake();
        $fake->refreshResult = new GoogleOAuthToken('new-access-token', 'new-refresh-token', Carbon::now()->addHour());
        $coach = User::factory()->coach()->create();
        $credential = GoogleCalendarCredential::factory()->forCoach($coach)->expired()->create([
            'access_token' => 'stale-token',
            'refresh_token' => 'refresh-token-1',
        ]);
        $fake->busyIntervalsByToken['new-access-token'] = [[
            'start' => Carbon::parse('2026-06-01 10:00:00'),
            'end' => Carbon::parse('2026-06-01 11:00:00'),
        ]];

        $result = $this->service()->busyIntervals($coach, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));

        $this->assertCount(1, $result);
        $this->assertCount(1, $fake->refreshCalls);
        $this->assertSame('refresh-token-1', $fake->refreshCalls[0]['refreshToken']);
        $this->assertSame('new-access-token', $credential->fresh()->access_token);
    }

    public function test_busy_intervals_does_not_refresh_when_token_still_valid(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create([
            'access_token' => 'still-valid-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->service()->busyIntervals($coach, now(), now()->addDay());

        $this->assertCount(0, $fake->refreshCalls);
    }
}
