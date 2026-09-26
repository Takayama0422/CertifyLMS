<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Notification;

use App\Enums\MeetingReminderWindow;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 面談リマインダー配信ユースケースの検証。
 * 観点: 予約済み面談のみ対象 / 前日・1 時間前それぞれの窓の境界 / 当事者双方への配信 /
 * 配信対象外の除外 / **同じ窓を 2 回実行しても二重配信しないこと(最重要)** /
 * **実運用のズレ(分がずれた面談・起動遅延・送信失敗・プロセス強制終了)を再現しても
 * 取りこぼさない / 二重配信しないこと**。
 */
class SendMeetingRemindersActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function action(): SendMeetingRemindersAction
    {
        return app(SendMeetingRemindersAction::class);
    }

    public function test_eve_window_targets_meetings_scheduled_for_tomorrow(): void
    {
        $tomorrow = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);
        $dayAfterTomorrow = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDays(2)->setTime(15, 0)]);
        $today = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addHours(3)]);

        $count = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $tomorrow->student_id, 'type' => MeetingReminderNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $tomorrow->coach_id, 'type' => MeetingReminderNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $dayAfterTomorrow->student_id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $today->student_id]);
    }

    public function test_one_hour_before_window_targets_meetings_starting_soon(): void
    {
        $soon = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addHour()]);
        $farLater = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addHours(3)]);
        $tooSoon = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addMinutes(5)]);

        $count = $this->action()(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $soon->student_id, 'type' => MeetingReminderNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $farLater->student_id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $tooSoon->student_id]);
    }

    public function test_one_hour_before_window_boundaries_are_55_to_65_minutes(): void
    {
        // PM 指摘: 対象窓は「コマンド実行時点から 55〜65 分後」に厳密に限定する(15〜60 分では要件が変わる)。
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00'));
        $tooEarly = Meeting::factory()->reserved()->create(['scheduled_at' => Carbon::parse('2026-06-01 09:54:00')]);
        $lowerBound = Meeting::factory()->reserved()->create(['scheduled_at' => Carbon::parse('2026-06-01 09:55:00')]);
        $upperBound = Meeting::factory()->reserved()->create(['scheduled_at' => Carbon::parse('2026-06-01 10:05:00')]);
        $tooLate = Meeting::factory()->reserved()->create(['scheduled_at' => Carbon::parse('2026-06-01 10:06:00')]);

        $count = $this->action()(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(4, $count);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $tooEarly->student_id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $lowerBound->student_id, 'type' => MeetingReminderNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $upperBound->student_id, 'type' => MeetingReminderNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $tooLate->student_id]);
    }

    public function test_canceled_and_completed_meetings_are_excluded(): void
    {
        $canceled = Meeting::factory()->canceled()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);
        $completed = Meeting::factory()->completed()->create();

        $count = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $canceled->student_id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $completed->student_id]);
    }

    public function test_withdrawn_party_is_excluded_but_other_party_still_notified(): void
    {
        $withdrawnCoach = User::factory()->coach()->withdrawn()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($withdrawnCoach)->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);

        $count = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $withdrawnCoach->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $meeting->student_id]);
    }

    public function test_running_the_same_window_twice_does_not_send_duplicate_notifications(): void
    {
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);

        $first = $this->action()(MeetingReminderWindow::Eve);
        $second = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(2, $first);
        $this->assertSame(0, $second, '2 回目の実行では二重配信されず 0 件であるべき');
        $this->assertDatabaseCount('meeting_reminder_dispatches', 2);
        $this->assertSame(
            2,
            DatabaseNotification::where('type', MeetingReminderNotification::class)
                ->whereIn('notifiable_id', [$meeting->student_id, $meeting->coach_id])
                ->count(),
        );
    }

    public function test_eve_and_one_hour_before_dispatch_records_are_scoped_independently(): void
    {
        $eveMeeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);
        $soonMeeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addHour()]);

        $eveCount = $this->action()(MeetingReminderWindow::Eve);
        $oneHourCount = $this->action()(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(2, $eveCount);
        $this->assertSame(2, $oneHourCount);
        $this->assertDatabaseHas('meeting_reminder_dispatches', ['meeting_id' => $eveMeeting->id, 'window' => 'eve']);
        $this->assertDatabaseHas('meeting_reminder_dispatches', ['meeting_id' => $soonMeeting->id, 'window' => 'one_hour_before']);
        $this->assertDatabaseMissing('meeting_reminder_dispatches', ['meeting_id' => $eveMeeting->id, 'window' => 'one_hour_before']);
        $this->assertDatabaseCount('meeting_reminder_dispatches', 4);

        // 再実行しても両窓とも増えない
        $this->assertSame(0, $this->action()(MeetingReminderWindow::Eve));
        $this->assertSame(0, $this->action()(MeetingReminderWindow::OneHourBefore));
        $this->assertDatabaseCount('meeting_reminder_dispatches', 4);
    }

    public function test_one_hour_before_window_catches_meetings_with_non_hour_aligned_minutes(): void
    {
        // コーチ対応可能時間帯は分単位で登録できるため、面談は毎時 00 分スロットとは限らない。
        // 窓の下限・上限のどちらにも寄らない非キリ番の面談(62 分後)でも捕捉できることを確認する。
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00'));
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => Carbon::parse('2026-06-01 10:02:00')]);

        $count = $this->action()(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $meeting->student_id, 'type' => MeetingReminderNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $meeting->coach_id, 'type' => MeetingReminderNotification::class]);
    }

    public function test_one_hour_before_window_does_not_catch_up_meetings_that_already_passed_the_window(): void
    {
        // PM 指摘により対象窓を 55〜65 分後に厳密化したため、起動が遅れて既に窓を通り過ぎた面談
        // (今から 20 分後)はもう one_hour_before の対象にならない(取りこぼし救済のための
        // 窓拡大はしない、という仕様変更後の挙動を明示する)。
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addMinutes(20)]);

        $count = $this->action()(MeetingReminderWindow::OneHourBefore);

        $this->assertSame(0, $count);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $meeting->student_id]);
    }

    public function test_eve_window_still_delivers_when_the_scheduled_20_00_run_was_skipped(): void
    {
        // 20:00 の回が落ちても、同日中の後続の回(Kernel 側で 20-23 時に複数回起動)で拾えることを、
        // 実行時刻を 22:00 にずらして検証する(日付のみで判定するため、時刻そのものには依存しない)。
        Carbon::setTestNow(Carbon::parse('2026-06-01 22:00:00'));
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => Carbon::parse('2026-06-02 15:00:00')]);

        $count = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $meeting->student_id, 'type' => MeetingReminderNotification::class]);
    }

    public function test_stale_unsent_reservation_is_reclaimed_and_retried(): void
    {
        // 送信プロセスが通知送信の途中で強制終了した想定: sent_at 未設定のまま古い予約行だけが残る
        // (delete による後始末が実行されなかったケース。レビュー指摘 2「プロセスが途中で落ちた場合」)。
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);
        MeetingReminderDispatch::factory()->create([
            'meeting_id' => $meeting->id,
            'window' => MeetingReminderWindow::Eve->value,
            'user_id' => $meeting->student_id,
            'sent_at' => null,
            'created_at' => now()->subMinutes(15),
        ]);

        $count = $this->action()(MeetingReminderWindow::Eve);

        // 学生分は取りこぼしの回収で再送、コーチ分は初回送信 → 合計 2 件
        $this->assertSame(2, $count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $meeting->student_id, 'type' => MeetingReminderNotification::class]);
        $this->assertNotNull(
            MeetingReminderDispatch::query()
                ->where('meeting_id', $meeting->id)
                ->where('user_id', $meeting->student_id)
                ->value('sent_at'),
            '取りこぼしの回収後は sent_at が確定しているはず',
        );
    }

    public function test_fresh_unsent_reservation_is_treated_as_in_flight_and_not_double_sent(): void
    {
        // 別プロセスが今まさに送信処理中である想定(予約したてで sent_at 未設定)。
        // 同時に 2 プロセスが走っても二重配信しないことの検証(取りこぼしとは区別して再送しない)。
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);
        MeetingReminderDispatch::factory()->create([
            'meeting_id' => $meeting->id,
            'window' => MeetingReminderWindow::Eve->value,
            'user_id' => $meeting->student_id,
            'sent_at' => null,
            'created_at' => now(),
        ]);

        $count = $this->action()(MeetingReminderWindow::Eve);

        // 学生分はスキップ(処理中とみなす)、コーチ分のみ配信
        $this->assertSame(1, $count);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $meeting->student_id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $meeting->coach_id, 'type' => MeetingReminderNotification::class]);
    }

    public function test_send_failure_deletes_the_reservation_so_the_next_run_can_retry(): void
    {
        // 送信失敗(メール送信で例外)が起きた受信者だけを次回の定期実行で再送できることを検証する
        // (レビュー指摘 2 の核心)。予約行を先に確定してしまうと、失敗した相手には永久に届かない。
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);

        MeetingReminderThrowingMailerDouble::$calls = 0;
        MeetingReminderThrowingMailerDouble::$shouldThrow = true;
        $this->app->bind(MailFactory::class, fn () => new MeetingReminderThrowingMailFactoryDouble);

        $first = $this->action()(MeetingReminderWindow::Eve);

        // 1 人目(学生)への送信で例外 → カウントされず、予約行も残らない(次回の再送に備える)
        $this->assertSame(1, $first, '失敗した 1 名を除いた件数のみカウントされるはず');
        $this->assertDatabaseMissing('meeting_reminder_dispatches', [
            'meeting_id' => $meeting->id,
            'user_id' => $meeting->student_id,
        ]);
        $this->assertDatabaseHas('meeting_reminder_dispatches', [
            'meeting_id' => $meeting->id,
            'user_id' => $meeting->coach_id,
        ]);

        // メーラーが復旧した状態で次回の定期実行を再現する
        MeetingReminderThrowingMailerDouble::$shouldThrow = false;
        $second = $this->action()(MeetingReminderWindow::Eve);

        $this->assertSame(1, $second, '前回失敗した学生分だけが再送されるはず(コーチへは再送されない)');
        $this->assertDatabaseHas('meeting_reminder_dispatches', [
            'meeting_id' => $meeting->id,
            'user_id' => $meeting->student_id,
        ]);
    }
}

/**
 * `MailFactory` の最小限のテストダブル。`mailer()` は常に同じ `Mailer` ダブルを返す。
 */
final class MeetingReminderThrowingMailFactoryDouble implements MailFactory
{
    public function mailer($name = null): MailerContract
    {
        return new MeetingReminderThrowingMailerDouble;
    }
}

/**
 * `Mailer` の最小限のテストダブル。`send()` 呼び出し回数を静的に数え、
 * `$shouldThrow` が true の間は最初の 1 回だけ例外を投げて「送信失敗」を再現する。
 */
final class MeetingReminderThrowingMailerDouble implements MailerContract
{
    public static int $calls = 0;

    public static bool $shouldThrow = false;

    public function to($users): static
    {
        return $this;
    }

    public function bcc($users): static
    {
        return $this;
    }

    public function raw($text, $callback): null
    {
        return null;
    }

    public function send($view, array $data = [], $callback = null): null
    {
        self::$calls++;

        if (self::$shouldThrow && self::$calls === 1) {
            throw new \RuntimeException('smtp down (test double)');
        }

        return null;
    }
}
