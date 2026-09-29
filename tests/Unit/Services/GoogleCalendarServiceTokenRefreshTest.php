<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\GoogleCalendar\DataTransfer\GoogleOAuthToken;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Carbon\Carbon;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\FakeGoogleCalendarClient;
use Tests\TestCase;

/**
 * T-A-04: `GoogleCalendarService` の「連携の期限切れ → 自動リフレッシュ → 再試行」「リフレッシュに失敗したときの
 * フォールバック」「削除済み予定の扱い」の検証(既存の `GoogleCalendarServiceTest` を補完する)。
 *
 * モック手法: 利用側のテストは、SDK の低レベルな詳細(HTTP / OAuth のやり取り)ではなく、カレンダー操作の
 * まとまった単位(`GoogleCalendarClient`)を `FakeGoogleCalendarClient` へ差し替えて行う。
 */
#[Group('external')]
#[Group('google-calendar')]
class GoogleCalendarServiceTokenRefreshTest extends TestCase
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

    private function coachWithCredential(array $credential = []): User
    {
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create($credential);

        return $coach;
    }

    // --- 期限切れ → 自動リフレッシュ → 再試行(取得だけでなく、登録・削除でも同じ) ---

    public function test_register_meeting_refreshes_an_expired_token_and_then_creates_the_event_with_the_new_token(): void
    {
        $fake = $this->fake();
        $fake->refreshResult = new GoogleOAuthToken('refreshed-access', 'refresh-1', Carbon::now()->addHour());
        $fake->nextEventId = 'evt_after_refresh';
        $coach = $this->coachWithCredential(['access_token' => 'stale', 'refresh_token' => 'refresh-1', 'token_expires_at' => now()->subMinute()]);
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->create();

        $this->service()->registerMeeting($meeting);

        $this->assertCount(1, $fake->refreshCalls);
        $this->assertCount(1, $fake->createdEvents);
        $this->assertSame('refreshed-access', $fake->createdEvents[0]['accessToken'], '再試行は更新後のトークンで行う');
        $this->assertSame('evt_after_refresh', $meeting->fresh()->google_calendar_event_id);
    }

    public function test_cancel_meeting_refreshes_an_expired_token_and_then_deletes_the_event_with_the_new_token(): void
    {
        $fake = $this->fake();
        $fake->refreshResult = new GoogleOAuthToken('refreshed-access', 'refresh-1', Carbon::now()->addHour());
        $coach = $this->coachWithCredential(['access_token' => 'stale', 'refresh_token' => 'refresh-1', 'token_expires_at' => now()->subMinute()]);
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->create(['google_calendar_event_id' => 'evt_to_delete']);

        $this->service()->cancelMeeting($meeting);

        $this->assertCount(1, $fake->refreshCalls);
        $this->assertSame('refreshed-access', $fake->deletedEvents[0]['accessToken']);
        $this->assertNull($meeting->fresh()->google_calendar_event_id);
    }

    public function test_refreshed_token_is_persisted_so_the_next_call_does_not_refresh_again(): void
    {
        $fake = $this->fake();
        $fake->refreshResult = new GoogleOAuthToken('refreshed-access', 'refresh-1', Carbon::now()->addHour());
        $coach = $this->coachWithCredential(['refresh_token' => 'refresh-1', 'token_expires_at' => now()->subMinute()]);

        $this->service()->busyIntervals($coach, now(), now()->addDay());
        $this->service()->busyIntervals($coach->fresh(), now(), now()->addDay());

        $this->assertCount(1, $fake->refreshCalls, '連携は一度更新すれば、有効期限内は再更新せず継続して使える');
    }

    public function test_refresh_response_without_a_new_refresh_token_keeps_the_stored_one(): void
    {
        $fake = $this->fake();
        $fake->refreshResult = new GoogleOAuthToken('refreshed-access', null, Carbon::now()->addHour());
        $coach = $this->coachWithCredential(['refresh_token' => 'refresh-kept', 'token_expires_at' => now()->subMinute()]);

        $this->service()->busyIntervals($coach, now(), now()->addDay());

        $this->assertSame('refresh-kept', $coach->googleCredential->fresh()->refresh_token);
    }

    public function test_token_that_expires_in_the_future_is_used_as_is_and_one_that_expired_a_second_ago_is_refreshed(): void
    {
        // 境界: 有効期限が「未来」なら更新しない / 「過去」なら更新する
        $fake = $this->fake();
        $fake->refreshResult = new GoogleOAuthToken('refreshed-access', 'r', Carbon::now()->addHour());
        $valid = $this->coachWithCredential(['refresh_token' => 'r', 'token_expires_at' => now()->addMinute()]);
        $expired = $this->coachWithCredential(['refresh_token' => 'r', 'token_expires_at' => now()->subSecond()]);

        $this->service()->busyIntervals($valid, now(), now()->addDay());
        $this->assertCount(0, $fake->refreshCalls);

        $this->service()->busyIntervals($expired, now(), now()->addDay());
        $this->assertCount(1, $fake->refreshCalls);
    }

    // --- リフレッシュに失敗したときのフォールバック(面談機能の根幹は止めない) ---

    public function test_busy_intervals_and_conflict_fall_back_to_free_when_the_refresh_fails(): void
    {
        $fake = $this->fake();
        $fake->refreshException = new RuntimeException('invalid_grant: Token has been expired or revoked.');
        $coach = $this->coachWithCredential(['access_token' => 'stale', 'refresh_token' => 'revoked', 'token_expires_at' => now()->subMinute()]);

        $busy = $this->service()->busyIntervals($coach, now(), now()->addDay());
        $conflict = $this->service()->hasConflict($coach->fresh(), now(), now()->addHour());

        $this->assertTrue($busy->isEmpty(), '空き枠の表示は止めず、従来どおりの空き判定にフォールバックする');
        $this->assertFalse($conflict, '衝突なし(予約可能)として扱う');
        $this->assertSame('stale', $coach->googleCredential->fresh()->access_token, '失敗したリフレッシュで保存済みの認証情報を壊さない');
    }

    public function test_register_meeting_does_not_fail_when_the_refresh_fails(): void
    {
        $fake = $this->fake();
        $fake->refreshException = new RuntimeException('invalid_grant');
        $coach = $this->coachWithCredential(['refresh_token' => 'revoked', 'token_expires_at' => now()->subMinute()]);
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->create();

        $this->service()->registerMeeting($meeting);

        $this->assertCount(0, $fake->createdEvents, 'トークンを更新できないので Google へ登録しない');
        $this->assertNull($meeting->fresh()->google_calendar_event_id);
    }

    public function test_cancel_meeting_does_not_fail_when_the_refresh_fails_and_keeps_the_event_id(): void
    {
        $fake = $this->fake();
        $fake->refreshException = new RuntimeException('invalid_grant');
        $coach = $this->coachWithCredential(['refresh_token' => 'revoked', 'token_expires_at' => now()->subMinute()]);
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->create(['google_calendar_event_id' => 'evt_keep']);

        $this->service()->cancelMeeting($meeting);

        $this->assertCount(0, $fake->deletedEvents);
        $this->assertSame('evt_keep', $meeting->fresh()->google_calendar_event_id);
    }

    public function test_expired_token_without_a_refresh_token_falls_back_without_calling_google(): void
    {
        $fake = $this->fake();
        $coach = $this->coachWithCredential(['refresh_token' => null, 'token_expires_at' => now()->subMinute()]);

        $busy = $this->service()->busyIntervals($coach, now(), now()->addDay());

        $this->assertTrue($busy->isEmpty());
        $this->assertCount(0, $fake->refreshCalls, 'refresh_token が無ければ更新を試みない');
    }

    // --- 削除済み予定の扱い ---

    public function test_cancel_meeting_does_not_fail_when_the_event_was_already_deleted_on_google(): void
    {
        // Google 側で予定が既に削除されている場合、削除 API は 410(または 404)を返す
        $fake = $this->fake();
        $fake->deleteEventException = new GoogleServiceException('Resource has been deleted', 410);
        $coach = $this->coachWithCredential();
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->create(['google_calendar_event_id' => 'evt_gone']);

        // 面談のキャンセル自体は止めない(例外を外へ出さない)
        $this->service()->cancelMeeting($meeting);

        $this->assertSame('evt_gone', $meeting->fresh()->google_calendar_event_id, '現状の仕様: 削除に失敗した予定 ID は保持する(410 でも同じ)');
    }
}
