<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\Services\NotificationRecipientService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 面談リマインダー配信ユースケース(要件シート S7/S9)。
 *
 * 対象は予約済み(reserved)の面談のみ(キャンセル済 / 完了済は対象外)。当事者(受講生 + 担当コーチ)へ、
 * 配信対象の除外規則(`NotificationRecipientService`)を通したうえで配信する。
 *
 * **配信窓**
 *
 * - `one_hour_before`: コマンド実行時点から「55〜65 分後」に開始する面談を対象にする
 *   (要件シート S12-05 の「開始 1 時間前」を維持する 10 分幅)。窓を過ぎた面談は対象外であり、
 *   後から遡って拾うことはしない。
 * - `eve`: 「翌日に予定されている面談」を対象にする(日付のみで判定。時刻では絞らない)。
 *   通知文言が「明日」と明言するため、日付をまたいでからの遅延配信はしない
 *   (日付が変わればその面談は `one_hour_before` 側で拾われる)
 *
 * **取りこぼし防止の担保箇所**: 本 Action ではなく `Kernel::schedule()` の起動間隔が担保する。
 * `one_hour_before` は起動間隔(10 分)を窓幅(10 分)以下に保つことで、連続する起動の走査範囲が
 * 隙間なく繋がり、どの分オフセットの面談も必ずいずれかの起動で窓に入る
 * (面談開始時刻はコーチ対応可能時間帯の登録次第で毎時 00 分とは限らないため、この連続性が要る)。
 * `eve` は日付のみで判定するため、20:00 の回が落ちても同日 23:00 までの後続の回で拾える。
 * **起動自体が窓幅を超えて止まった場合、`one_hour_before` はその間の面談を取りこぼす**
 * (下記の配信済み記録はこれを救済しない)。
 *
 * **二重配信防止(本チケットの肝)**: 送信前に `meeting_reminder_dispatches` へ
 * (面談, 配信窓, 受信者)の組を UNIQUE 制約付きで INSERT する「予約」を試みる。
 * INSERT が成功した場合のみ通知を送る。同時実行 / 再実行、および隣接する 2 回の起動の
 * 窓の境界(ちょうど 55 分後 / 65 分後)に重なった面談でも、DB の UNIQUE 制約が単一の勝者だけを
 * 許すため確実に一度しか送られない(アプリ側のロジックだけに頼らない)。
 *
 * **送信失敗時の再送**: 予約行は `notify()` の呼び出しが例外を投げずに完了した時点で `sent_at` を書き込んで
 * 確定する。`notify()` 自体が例外を投げた場合(枠の予約や配信処理の起票そのものに失敗した場合)は
 * 予約行ごと削除し、後続の定期実行で同じ受信者へ再送を試みられるようにする。プロセスが途中で
 * 強制終了する等、削除処理まで辿り着けなかった場合に備え、`sent_at` が未設定のまま一定時間
 * (`STALE_RESERVATION_MINUTES`)経過した予約行は再利用可能とする。
 * ただしこの再送はいずれも「その面談がまだ配信窓に入っている」場合にのみ働く。
 * `one_hour_before` は窓が 10 分幅のため、実質的に再送の機会があるのは同一窓内に別の起動が
 * 重なったときに限られる(`eve` は同日中の後続の回で再送できる)。
 *
 * **T-A-05 でのキュー非同期化との関係**: `MeetingReminderNotification`(`BusinessEventNotification`
 * 経由)は `ShouldQueueAfterCommit` を実装するため、`notify()` は実際の送信(database 書き込み +
 * mail 送信)を待たず、バックグラウンドジョブとしてキューへ積んだ時点で正常終了する。つまり上記の
 * try/catch が捕捉するのは「キューへの投入自体の失敗」であり、「積んだジョブが worker 側で
 * 送信に失敗するケース」はここでは捕捉されない(捕捉しようにも、その時点では `dispatchTo()` は
 * 既に呼び出し元へ復帰済み)。後者は Laravel のキューが持つ段階的リトライ(`$tries` / `backoff()`)
 * と、上限超過時の `failed_jobs` 記録・`queue:retry` による再投入に委ねる(窓を過ぎた面談を後から
 * 拾い直す経路ではない)。二重配信防止そのものは「(面談, 配信窓, 受信者)の組を UNIQUE 制約で
 * 一度しか予約させない」という DB 制約に依存しており、配信処理が同期か非同期かに関わらず有効である。
 */
final class SendMeetingRemindersAction
{
    /** `one_hour_before` の対象窓の下限(コマンド実行時点からの分数)。 */
    private const ONE_HOUR_BEFORE_LOWER_MINUTES = 55;

    /** `one_hour_before` の対象窓の上限(コマンド実行時点からの分数)。 */
    private const ONE_HOUR_BEFORE_UPPER_MINUTES = 65;

    /**
     * `sent_at` 未設定のまま何分経過した予約行を「送信プロセスが落ちた取りこぼし」とみなし
     * 再利用してよいか。通常の送信処理(DB 予約 + 通知送信)はこれより大幅に短時間で終わる想定。
     */
    private const STALE_RESERVATION_MINUTES = 10;

    public function __invoke(MeetingReminderWindow $window): int
    {
        $sentCount = 0;

        $this->resolveMeetingsQuery($window)
            ->with(['student', 'coach'])
            ->orderBy('id')
            ->chunkById(100, function ($meetings) use ($window, &$sentCount): void {
                foreach ($meetings as $meeting) {
                    foreach ($this->partiesFor($meeting) as $recipient) {
                        if ($this->dispatchTo($recipient, $meeting, $window)) {
                            $sentCount++;
                        }
                    }
                }
            });

        return $sentCount;
    }

    /**
     * @return array<int, ?User>
     */
    private function partiesFor(Meeting $meeting): array
    {
        return [$meeting->student, $meeting->coach];
    }

    private function dispatchTo(?User $recipient, Meeting $meeting, MeetingReminderWindow $window): bool
    {
        if ($recipient === null || ! NotificationRecipientService::eligibleForEventNotification($recipient)) {
            return false;
        }

        if (! $this->reserve($meeting, $window, $recipient)) {
            // 既に送信確定済み、または他プロセスが送信処理中(予約したてでまだ新しい)ため見送る
            return false;
        }

        try {
            // T-A-05: notify() はキューへの投入までを担う(実送信は worker が非同期に行う)。
            // ここで捕捉するのは投入自体の失敗のみ。worker 側の送信失敗はキューの自動リトライ /
            // failed_jobs に委ねる(クラスの docblock 参照)。
            $recipient->notify(new MeetingReminderNotification($meeting, $window));
        } catch (Throwable $e) {
            report($e);

            // キューへの投入に失敗: この受信者だけをスキップし、予約行を削除して
            // 次回の定期実行で再送できるようにする(他の面談 / 受信者の処理は続ける)。
            MeetingReminderDispatch::query()
                ->where('meeting_id', $meeting->id)
                ->where('window', $window->value)
                ->where('user_id', $recipient->id)
                ->whereNull('sent_at')
                ->delete();

            return false;
        }

        MeetingReminderDispatch::query()
            ->where('meeting_id', $meeting->id)
            ->where('window', $window->value)
            ->where('user_id', $recipient->id)
            ->update(['sent_at' => Carbon::now()]);

        return true;
    }

    /**
     * (面談, 配信窓, 受信者)の配信枠を UNIQUE 制約で予約する。
     *
     * - 未予約なら INSERT して true(予約成功、送信してよい)
     * - 既に送信確定済み(`sent_at` あり)なら false(二重配信防止)
     * - 予約はされたが `sent_at` が未設定のまま新しい(他プロセスが送信処理中の可能性)なら false
     *   (同時実行時の二重配信防止。取りこぼしではなく処理中とみなす)
     * - 予約はされたが `sent_at` が未設定のまま `STALE_RESERVATION_MINUTES` 以上経過している
     *   (送信プロセスが落ちた等)なら、その行を再利用して true(取りこぼしの回収)
     */
    private function reserve(Meeting $meeting, MeetingReminderWindow $window, User $recipient): bool
    {
        try {
            MeetingReminderDispatch::query()->create([
                'meeting_id' => $meeting->id,
                'window' => $window->value,
                'user_id' => $recipient->id,
                'sent_at' => null,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return $this->reclaimStaleReservation($meeting, $window, $recipient);
        }
    }

    /**
     * 送信未確定のまま長時間放置された予約行を、UPDATE 1 発で原子的に再予約する
     * (行ロックにより、複数プロセスが同時に再利用を試みても勝者は 1 つに絞られる)。
     */
    private function reclaimStaleReservation(Meeting $meeting, MeetingReminderWindow $window, User $recipient): bool
    {
        $updated = MeetingReminderDispatch::query()
            ->where('meeting_id', $meeting->id)
            ->where('window', $window->value)
            ->where('user_id', $recipient->id)
            ->whereNull('sent_at')
            ->where('created_at', '<=', Carbon::now()->subMinutes(self::STALE_RESERVATION_MINUTES))
            ->update(['created_at' => Carbon::now()]);

        return $updated > 0;
    }

    /**
     * @return Builder<Meeting>
     */
    private function resolveMeetingsQuery(MeetingReminderWindow $window): Builder
    {
        $query = Meeting::query()->where('status', MeetingStatus::Reserved->value);

        return match ($window) {
            // 「翌日」の予約すべてが対象(要件シート S12-05)。時刻では絞らないため、20:00 の起動が
            // 1 回落ちても、同じ日付のうちに再実行(Kernel 側で複数回起動)すれば取りこぼさない。
            MeetingReminderWindow::Eve => $query->whereDate('scheduled_at', Carbon::now()->addDay()->toDateString()),
            // コマンド実行時点から「55〜65 分後」に開始する面談を対象にする(要件シート S12-05 の
            // 「開始 1 時間前」を維持する 10 分幅)。取りこぼし防止は Kernel::schedule() の起動間隔
            // (10 分)を本窓幅(10 分)以下に保つことで担保し、窓の境界に重なった面談の重複防止は
            // (面談, 配信窓, 受信者) 単位の配信済み記録(reserve/reclaim)側の UNIQUE 制約で担保する。
            MeetingReminderWindow::OneHourBefore => $query->whereBetween('scheduled_at', [
                Carbon::now()->addMinutes(self::ONE_HOUR_BEFORE_LOWER_MINUTES),
                Carbon::now()->addMinutes(self::ONE_HOUR_BEFORE_UPPER_MINUTES),
            ]),
        };
    }
}
