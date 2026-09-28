<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Events\MeetingReserved;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\GoogleCalendar\DataTransfer\GoogleOAuthToken;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FakeGoogleCalendarClient;
use Tests\TestCase;

/**
 * S-A-01: 面談予約 / キャンセルへの Google カレンダー連携フックを検証する既存 `MeetingControllerTest`
 * の補完テスト(既存テストファイルは書き換え禁止のため別ファイルに追加する)。
 *
 * 実通信は `FakeGoogleCalendarClient` に完全に差し替え、実通信を一切発生させない。
 */
class MeetingControllerGoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
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

    public function test_booking_registers_google_calendar_event_for_connected_coach(): void
    {
        $fake = $this->fake();
        $fake->nextEventId = 'evt_booking_1';
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://meet.example.com/coach-room']);
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $response->assertRedirect();
        $this->assertCount(1, $fake->createdEvents);
        $meeting = Meeting::query()->where('coach_id', $coach->id)->firstOrFail();
        $this->assertSame('evt_booking_1', $meeting->google_calendar_event_id);
    }

    public function test_booking_excludes_coach_busy_on_google_calendar(): void
    {
        $fake = $this->fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $credential = GoogleCalendarCredential::factory()->forCoach($coach)->create(['access_token' => 'tok-busy']);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        // Google 側で当該時刻に既に予定あり(唯一の担当コーチがこの時刻だけ busy)
        $fake->busyIntervalsByToken['tok-busy'] = [[
            'start' => $scheduledAt->copy(),
            'end' => $scheduledAt->copy()->addHour(),
        ]];

        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // 空きコーチが 0 名になるため予約は成立しない(409 → HTML では redirect back + error flash)
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_booking_succeeds_even_when_google_registration_fails(): void
    {
        $fake = $this->fake();
        $fake->createEventException = new RuntimeException('Google API error');
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // Google 登録が失敗しても面談予約自体は成立する(面談機能の根幹は止めない)
        $response->assertRedirect();
        $this->assertDatabaseHas('meetings', [
            'coach_id' => $coach->id,
            'status' => MeetingStatus::Reserved->value,
        ]);
    }

    public function test_cancel_deletes_google_calendar_event(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $student = User::factory()->student()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
            'google_calendar_event_id' => 'evt_to_cancel',
        ]);

        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $response->assertRedirect();
        $this->assertCount(1, $fake->deletedEvents);
        $this->assertSame('evt_to_cancel', $fake->deletedEvents[0]['eventId']);
        $this->assertNull($meeting->fresh()->google_calendar_event_id);
    }

    public function test_cancel_succeeds_even_when_google_deletion_fails(): void
    {
        $fake = $this->fake();
        $fake->deleteEventException = new RuntimeException('Google API error');
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $student = User::factory()->student()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
            'google_calendar_event_id' => 'evt_to_cancel',
        ]);

        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        // Google 削除が失敗してもキャンセル自体は成立する
        $response->assertRedirect();
        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'status' => MeetingStatus::Canceled->value,
        ]);
    }

    /**
     * Google 通信は予約確定の DB トランザクションの外で行う(空き確認は確定前、予定の登録は確定後)。
     * 通信をトランザクション内に置くと、通信が終わるまでロックを保持し、トークン更新の保存も
     * 予約失敗で巻き戻る。RefreshDatabase のテスト全体を包むトランザクションの段数を基準に比較する。
     */
    public function test_google_communication_happens_outside_the_reservation_transaction(): void
    {
        $fake = $this->fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://meet.example.com/coach-room']);
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);
        $baseline = DB::transactionLevel();

        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $this->assertNotEmpty($fake->transactionLevels['busy'], '空き確認で Google と通信していない');
        $this->assertCount(1, $fake->transactionLevels['create']);
        foreach (['busy', 'create'] as $kind) {
            foreach ($fake->transactionLevels[$kind] as $level) {
                $this->assertSame($baseline, $level, "Google の {$kind} 通信が DB トランザクションの内側で行われている");
            }
        }
    }

    public function test_cancel_deletes_google_event_after_the_transaction_commits(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $student = User::factory()->student()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
            'google_calendar_event_id' => 'evt_to_cancel',
        ]);
        $baseline = DB::transactionLevel();

        $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $this->assertCount(1, $fake->transactionLevels['delete']);
        $this->assertSame($baseline, $fake->transactionLevels['delete'][0], 'Google の削除がキャンセル確定の DB トランザクションの内側で行われている');
    }

    /**
     * 予約が巻き戻った場合は、Google カレンダーにも予定を登録しない(外部へ登録済みの予定だけが残る不整合を防ぐ)。
     * `MeetingReserved` の発火より後(同一トランザクション内)で失敗させ、コミット後実行になっていなければ
     * 巻き戻る前に Google へ登録されてしまう状況を再現する。
     */
    public function test_google_event_is_not_registered_when_reservation_rolls_back(): void
    {
        $fake = $this->fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://meet.example.com/coach-room']);
        GoogleCalendarCredential::factory()->forCoach($coach)->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        // 本体のリスナーより後に登録されるため、Google 登録リスナーの後で失敗してトランザクションが巻き戻る。
        Event::listen(MeetingReserved::class, function (): void {
            throw new RuntimeException('イベント発火後の失敗(予約を巻き戻す)');
        });

        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $this->assertDatabaseCount('meetings', 0);
        $this->assertSame([], $fake->createdEvents, '巻き戻った予約に対して Google へ予定が登録されている');
    }

    /**
     * 複数の担当コーチがいて一部だけ Google 側で予定が埋まっている場合、埋まっているコーチには割り当てない。
     * 負荷分散(過去 30 日の completed 数が最少のコーチ)だけなら busy 側が選ばれる状況にして、除外を検証する。
     */
    public function test_booking_is_assigned_to_a_free_coach_when_another_coach_is_busy_on_google(): void
    {
        $fake = $this->fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $busyCoach = User::factory()->coach()->inProgress()->create();
        $freeCoach = User::factory()->coach()->inProgress()->create();
        GoogleCalendarCredential::factory()->forCoach($busyCoach)->create(['access_token' => 'tok-busy']);
        $certification = Certification::factory()->published()->create();
        foreach ([$busyCoach, $freeCoach] as $coach) {
            $this->attachCoach($certification, $coach, $admin);
            CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        }
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        // busy 側は completed 0 件、free 側は 1 件 → 負荷分散だけなら busy 側が最少で選ばれてしまう
        Meeting::factory()->completed()->forCoach($freeCoach)->forStudent(User::factory()->student()->create())->create([
            'scheduled_at' => now()->subDays(5)->startOfHour(),
        ]);
        $fake->busyIntervalsByToken['tok-busy'] = [[
            'start' => $scheduledAt->copy(),
            'end' => $scheduledAt->copy()->addHour(),
        ]];

        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $this->assertDatabaseHas('meetings', [
            'student_id' => $student->id,
            'coach_id' => $freeCoach->id,
            'status' => MeetingStatus::Reserved->value,
        ]);
        $this->assertDatabaseMissing('meetings', ['student_id' => $student->id, 'coach_id' => $busyCoach->id]);
    }

    public function test_unconnected_coach_booking_and_cancel_unaffected(): void
    {
        $fake = $this->fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        // Google 連携なし
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        $response->assertRedirect();
        $this->assertCount(0, $fake->createdEvents);
        $meeting = Meeting::query()->where('coach_id', $coach->id)->firstOrFail();
        $this->assertNull($meeting->google_calendar_event_id);

        $this->actingAs($student)->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertCount(0, $fake->deletedEvents);
    }

    /**
     * S-A-01 コードレビュー指摘対応: 空き確認(Google 通信を伴う)を DB トランザクションの外へ出した
     * ことの回帰テスト。
     *
     * 空き確認中にアクセストークンの自動更新(DB 保存)が起き、かつ結果として空きコーチが 0 名になり
     * 予約自体は失敗する状況を作る。従来は空き確認が予約の DB トランザクション内にあったため、
     * 予約失敗時にこのトークン更新の保存も一緒に巻き戻っていた。
     * 空き確認をトランザクションの外に出した現在は、予約が失敗してもトークン更新は残り続けるはず。
     */
    public function test_token_refresh_during_availability_check_survives_booking_failure(): void
    {
        $fake = $this->fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $credential = GoogleCalendarCredential::factory()->forCoach($coach)->expired()->create([
            'access_token' => 'stale-token',
            'refresh_token' => 'refresh-token-1',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        // 空き確認中にトークンがリフレッシュされる。リフレッシュ後のトークンで Google 側は当該時刻を busy
        // と返す(= 唯一の担当コーチが busy になり、空きコーチが 0 名で予約自体は失敗する)。
        $fake->refreshResult = new GoogleOAuthToken('refreshed-token', 'refresh-token-1', Carbon::now()->addHour());
        $fake->busyIntervalsByToken['refreshed-token'] = [[
            'start' => $scheduledAt->copy(),
            'end' => $scheduledAt->copy()->addHour(),
        ]];

        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // 予約自体は空きコーチ 0 名で失敗する
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('meetings', 0);

        // それでもトークン更新は巻き戻らず残っている(空き確認が DB トランザクションの外で行われた証拠)
        $this->assertSame('refreshed-token', $credential->fresh()->access_token);
        $this->assertCount(1, $fake->refreshCalls);
    }
}
