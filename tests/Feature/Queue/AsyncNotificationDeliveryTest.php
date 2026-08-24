<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\AnnouncementTargetType;
use App\Enums\MeetingReminderWindow;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\Notifications\MeetingReservedNotification;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
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
        config(['queue.default' => 'database']);
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

    public function test_meeting_reserved_notification_is_not_queued_when_the_wrapping_transaction_rolls_back(): void
    {
        $this->useDatabaseQueue();
        $meeting = Meeting::factory()->reserved()->create();

        try {
            DB::transaction(function () use ($meeting): void {
                $meeting->student->notify(new MeetingReservedNotification($meeting));

                throw new RuntimeException('force rollback (test)');
            });
        } catch (RuntimeException) {
            // 期待どおりのロールバック
        }

        // ロールバックされたトランザクション内で発火した通知はキューへ積まれてはならない
        $this->assertDatabaseCount('jobs', 0);
    }

    // --- 壊れやすい点 1: ジョブ実行時にモデルが再取得される。対象が削除済みでも破綻しないこと ---

    public function test_serialized_reminder_notification_throws_model_not_found_when_meeting_is_deleted_before_processing(): void
    {
        $meeting = Meeting::factory()->reserved()->create();
        $notification = new MeetingReminderNotification($meeting, MeetingReminderWindow::Eve);

        // SendQueuedNotifications ジョブが実際に行うのと同じ「シリアライズ → (時間経過) → アンシリアライズ」
        // を模擬する。Notification 基底クラスの SerializesModels により、Eloquent モデルは ID 参照へ
        // 変換されてシリアライズされ、アンシリアライズ時に DB から再取得される。
        $serialized = serialize($notification);

        $meeting->delete();

        // 再取得先が消えている場合、ModelNotFoundException という「型のついた」例外が飛ぶ。
        // これはキュー worker が捕捉して 1 ジョブだけを失敗させる例外であり、worker プロセスや
        // 他のジョブを巻き込むフェイタルエラーにはならない。
        $this->expectException(ModelNotFoundException::class);

        unserialize($serialized);
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
