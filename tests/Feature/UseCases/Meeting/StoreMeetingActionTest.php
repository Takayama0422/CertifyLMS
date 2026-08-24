<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Events\MeetingReserved;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\UseCases\Meeting\StoreMeetingAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreMeetingActionTest extends TestCase
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

    public function test_creates_reserved_meeting_consumes_quota_and_fires_event(): void
    {
        Event::fake();

        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = Carbon::now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $meeting = app(StoreMeetingAction::class)($enrollment, $scheduledAt, '相談したい');

        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertSame($student->id, $meeting->student_id);
        $this->assertSame($coach->id, $meeting->coach_id);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'amount' => -1,
        ]);
        Event::assertDispatched(MeetingReserved::class, fn (MeetingReserved $event) => $event->meeting->id === $meeting->id);
    }

    public function test_throws_when_quota_is_insufficient(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 0]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = Carbon::now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        try {
            app(StoreMeetingAction::class)($enrollment, $scheduledAt, '相談したい');
            $this->fail('InsufficientMeetingQuotaException が送出されていない。');
        } catch (InsufficientMeetingQuotaException) {
            // 期待どおり。残数不足で予約が成立していないことを続けて検証する。
        }

        $this->assertDatabaseCount('meetings', 0);
    }

    /**
     * コードレビュー指摘 5(T-A-02)の回帰テスト。
     *
     * `MeetingReserved` イベント発火(→ 通知配信)を予約確定と同一の DB トランザクション境界に
     * 含めたことがこのチケットで唯一の挙動変更点。境界の外に出すリグレッションを検知できるよう、
     * イベント発火時点でトランザクションが何段深いかを確認する
     * (RefreshDatabase のテスト全体を包むラップ用トランザクションの 1 段さらに内側にいるはず)。
     */
    public function test_meeting_reserved_event_fires_inside_the_reservation_transaction(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = Carbon::now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $levelBeforeDispatch = DB::transactionLevel();
        $levelAtDispatch = null;
        Event::listen(MeetingReserved::class, function () use (&$levelAtDispatch): void {
            $levelAtDispatch = DB::transactionLevel();
        });

        app(StoreMeetingAction::class)($enrollment, $scheduledAt, '相談したい');

        $this->assertNotNull($levelAtDispatch, 'MeetingReserved イベントが発火していない');
        $this->assertSame(
            $levelBeforeDispatch + 1,
            $levelAtDispatch,
            'MeetingReserved イベントは StoreMeetingAction 自身の DB::transaction() の内側で発火するはず'
            .'(テスト全体を包む RefreshDatabase のラップ用トランザクションより 1 段深い)',
        );
    }

    /**
     * コードレビュー指摘 5(T-A-02)の回帰テスト。
     *
     * 予約(Meeting::create)が成立した後、同一トランザクション内の後続処理
     * (面談回数消費 ConsumeQuotaAction)が失敗した場合、予約も通知も一切残らないことを検証する。
     */
    public function test_meeting_and_notification_do_not_persist_when_quota_consumption_fails_after_reservation(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 1]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $scheduledAt = Carbon::now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        // StoreMeetingAction 冒頭の残数チェック(1 回目、max_meetings=1 なので通過)の後、
        // Meeting::create() 成立の直後(ConsumeQuotaAction 呼び出しより前)に横取り消費を差し込み、
        // ConsumeQuotaAction 内部の残数チェック(2 回目)だけを枯渇させて後続失敗を再現する
        // (final クラスの MeetingQuotaService は Mockery で差し替えられないため、実データで再現する)。
        Meeting::created(function (Meeting $meeting) use ($student): void {
            if ($meeting->student_id === $student->id) {
                MeetingQuotaTransaction::factory()->for($student, 'user')->consumed($meeting->id)->create();
            }
        });

        try {
            try {
                app(StoreMeetingAction::class)($enrollment, $scheduledAt, '相談したい');
                $this->fail('InsufficientMeetingQuotaException が送出されていない。');
            } catch (InsufficientMeetingQuotaException) {
                // 期待どおり。
            }
        } finally {
            Meeting::flushEventListeners();
        }

        // 後続失敗時は予約(Meeting)・消費履歴(横取り分含む)・通知のいずれも残ってはならない。
        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->assertDatabaseCount('notifications', 0);
    }
}
