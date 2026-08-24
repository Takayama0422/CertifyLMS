<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\AnnouncementTargetType;
use App\Enums\MeetingReminderWindow;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * T-A-05(通知・メール配信の非同期化)の壊れやすい点を検証する。
 *
 * `phpunit.xml` は既定で `QUEUE_CONNECTION=sync` にしているため(他の大半のテストが前提とする
 * 「HTTP レスポンスが返る頃には通知が届いている」という同期的な検証を壊さないため)、本ファイルは
 * 個々のテストで `QUEUE_CONNECTION=database` へ明示的に切り替え、実際に `jobs` テーブルへ積まれた
 * ままワーカーが処理していない状態を作って検証する(sync だと push と同時に処理されてしまい、
 * 「積まれているだけでまだ届いていない」状態を再現できないため)。
 */
class AsyncNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function useDatabaseQueue(): void
    {
        config([
            'queue.default' => 'database',
            // 失敗ジョブの記録先を、テストが実際に使っている接続へ揃える
            'queue.failed.database' => config('database.default'),
        ]);
    }

    /**
     * mail チャネルの送信を必ず失敗させる擬似トランスポートへ差し替える。
     *
     * 一時的な送信失敗(SMTP 接続エラー等)を再現するためのもので、送信内容そのものは変えない。
     */
    private function useFailingMailTransport(): void
    {
        Mail::extend('failing', fn (array $config = []): AbstractTransport => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP 接続に失敗しました(テスト用の擬似障害)');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });

        config([
            'mail.mailers.failing' => ['transport' => 'failing'],
            'mail.default' => 'failing',
        ]);

        Mail::forgetMailers();
    }

    /** worker を 1 ジョブ分だけ動かす(待機時間が明けていないジョブは拾われない)。 */
    private function workOneJob(): void
    {
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
    }

    /** キューに 1 件だけ残っているジョブの試行回数。 */
    private function pendingJobAttempts(): int
    {
        return (int) DB::table('jobs')->value('attempts');
    }

    // --- 壊れやすい点 3: データ確定(トランザクション commit)後に送信をキューへ投入する ---

    public function test_invitation_mail_is_not_queued_when_the_wrapping_transaction_rolls_back(): void
    {
        $this->useDatabaseQueue();
        $invitation = Invitation::factory()->pending()->create();

        try {
            DB::transaction(function () use ($invitation): void {
                Mail::send(new InvitationMail($invitation));

                throw new RuntimeException('force rollback (test)');
            });
        } catch (RuntimeException) {
            // 期待どおりのロールバック
        }

        // ロールバックされたトランザクション内で送信した招待メールはキューへ積まれてはならない
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_invitation_mail_is_queued_once_the_wrapping_transaction_commits(): void
    {
        $this->useDatabaseQueue();
        $invitation = Invitation::factory()->pending()->create();

        DB::transaction(function () use ($invitation): void {
            Mail::send(new InvitationMail($invitation));
        });

        // commit 後は招待メールがキューへ積まれているはず
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_meeting_reserved_notification_is_queued_only_after_the_wrapping_transaction_commits(): void
    {
        $this->useDatabaseQueue();
        $meeting = Meeting::factory()->reserved()->create();

        // 1) ロールバックした場合: 通知はキューへ積まれない(配信が漏れない)
        try {
            DB::transaction(function () use ($meeting): void {
                $meeting->student->notify(new MeetingReservedNotification($meeting));

                throw new RuntimeException('force rollback (test)');
            });
        } catch (RuntimeException) {
            // 期待どおりのロールバック
        }

        $this->assertDatabaseCount('jobs', 0);

        // 2) commit した場合: commit 前は積まれず、commit 後に積まれる
        $queuedBeforeCommit = null;

        DB::transaction(function () use ($meeting, &$queuedBeforeCommit): void {
            $meeting->student->notify(new MeetingReservedNotification($meeting));

            $queuedBeforeCommit = DB::table('jobs')->count();
        });

        // commit 前に積まれていたら、`ShouldQueueAfterCommit` ではなく素の `ShouldQueue` になっている
        $this->assertSame(0, $queuedBeforeCommit, 'commit 前にキューへ積まれてはならない');

        // commit 後は「受信者 1 名 × database / mail の 2 チャネル」= 2 件。
        // 通知が同期送信のままなら 0 件のままとなり、この検証で落ちる
        $this->assertDatabaseCount('jobs', 2);

        // worker 未処理のためまだ配信されていない。
        // 同期送信のままなら commit 済みの database 通知が 1 件残り、この検証で落ちる
        $this->assertDatabaseCount('notifications', 0);
    }

    // --- 壊れやすい点 1: 送信失敗時の段階的リトライと、上限超過時の失敗ジョブ記録・再投入 ---

    public function test_mail_delivery_failure_is_retried_with_staged_backoff_and_finally_recorded_as_a_failed_job(): void
    {
        $this->useDatabaseQueue();
        $this->useFailingMailTransport();

        $meeting = Meeting::factory()->reserved()->create();
        $meeting->student->notify(new MeetingReservedNotification($meeting));

        // 同期送信のままなら notify() の中で送信例外が発火元へ伝播し、ここへ到達できない。
        // 非同期化されていれば「受信者 1 名 × database / mail の 2 チャネル」= 2 件が積まれるだけ
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('failed_jobs', 0);

        // 1 回目: database チャネルのジョブは成功して消える(mail チャネルのジョブだけが残る)
        $this->workOneJob();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('jobs', 1);

        // 2 回目: mail チャネルのジョブが失敗し、キューから消えずに再投入される
        $this->workOneJob();
        $this->assertSame(1, $this->pendingJobAttempts());
        $this->assertDatabaseCount('failed_jobs', 0);

        // 待機時間が明けるまでは worker が拾わない(= 段階的な待機を挟んでいる)
        $this->workOneJob();
        $this->assertSame(1, $this->pendingJobAttempts());

        // 待機を明けさせながら、上限(`$tries` = 5)の手前まで試行させる
        foreach ([2, 3, 4] as $expectedAttempts) {
            $this->travel(901)->seconds();
            $this->workOneJob();

            $this->assertSame($expectedAttempts, $this->pendingJobAttempts());
            $this->assertDatabaseCount('failed_jobs', 0);
        }

        // 5 回目の試行で上限に達し、失敗ジョブとして記録される(送信はロストしない)
        $this->travel(901)->seconds();
        $this->workOneJob();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);

        // 記録された失敗ジョブは後から再投入できる
        Artisan::call('queue:retry', ['id' => ['all']]);

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 1);
    }

    // --- 壊れやすい点 2: お知らせ配信の「配信実績の記録」と「通知の送信」の整合 ---

    public function test_announcement_dispatched_count_is_recorded_synchronously_independent_of_async_delivery(): void
    {
        $this->useDatabaseQueue();
        $admin = User::factory()->admin()->create();
        $eligible1 = User::factory()->student()->inProgress()->create();
        $eligible2 = User::factory()->student()->inProgress()->create();

        $announcement = app(DispatchAnnouncementAction::class)($admin, [
            'title' => '配信テスト',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
        ]);

        // 配信実績(対象人数)はアクションの戻り値の時点で確定している
        $this->assertSame(2, $announcement->dispatched_count);
        $this->assertNotNull($announcement->dispatched_at);

        // この時点ではまだ worker が処理していないため、実際の配信(database 通知の書き込み)は
        // 起きていない。「記録」と「送信」が非同期化によってズレても、記録側の正しさは揺らがないこと
        // を確認する(送信が非同期に完了しても dispatched_count を書き換える経路は存在しない)。
        // 受講生 2 名分の通知ジョブがキューに積まれているはず。Laravel は「対象 × チャネル」ごとに
        // 個別の SendQueuedNotifications ジョブを積む(database チャネルと mail チャネルは別ジョブ)
        // ため、対象 2 名 × 2 チャネル = 4 件になる。
        $this->assertDatabaseCount('jobs', 4);
        // worker 未処理の間はまだ配信されていないはず
        $this->assertDatabaseCount('notifications', 0);
    }

    // --- 壊れやすい点 4(最重要): 面談リマインダーの二重配信防止が非同期でも効き続けること ---

    public function test_reminder_double_dispatch_guard_holds_even_when_delivery_is_genuinely_async(): void
    {
        $this->useDatabaseQueue();
        $meeting = Meeting::factory()->reserved()->create(['scheduled_at' => now()->addDay()->setTime(15, 0)]);
        $action = app(SendMeetingRemindersAction::class);

        $first = $action(MeetingReminderWindow::Eve);
        $second = $action(MeetingReminderWindow::Eve);

        $this->assertSame(2, $first, '初回は学生・コーチの 2 名分をキューへ積むはず');
        $this->assertSame(0, $second, '2 回目の実行は二重配信ガードにより 0 件のはず');

        // worker がまだ 1 件も処理していないにもかかわらず、2 回目の実行が 0 件になっている。
        // つまり二重配信防止は「実際に届いたかどうか」ではなく「キューへの投入を予約できたか」の
        // 時点で効いている(UNIQUE 制約による予約が先に成立するため、配信が同期か非同期かに依存しない)。
        // ジョブは学生・コーチ分(2 名 × database/mail 2 チャネル = 4 件)だけで、2 回目実行分は増えないはず
        $this->assertDatabaseCount('jobs', 4);
        // worker 未処理のためまだ配信は完了していないはず
        $this->assertDatabaseCount('notifications', 0);
        // 二重配信防止用の予約行も 2 件のまま増えないはず
        $this->assertDatabaseCount('meeting_reminder_dispatches', 2);
    }
}
