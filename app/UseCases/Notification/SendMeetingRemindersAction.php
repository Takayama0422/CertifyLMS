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
 * **窓の判定方針(レビュー指摘対応)**: 「特定の起動時刻ちょうど」を狙う点判定ではなく、
 * 「まだ送っていない(=`meeting_reminder_dispatches` に確定行が無い) & 送るべき時刻を過ぎている」
 * ものを毎回まとめて拾う方式にする。起動が遅れる / 1 回分の起動が丸ごと落ちる / 面談の開始時刻が
 * 分単位でずれている(コーチ対応可能時間帯は分単位で登録できる)場合でも、次回以降の実行で
 * 確実に拾える。
 *
 * - `one_hour_before`: 「開始 15 分前〜1 時間前」を対象にする(要件シート S12-05 の「開始 1 時間前」を
 *   45 分幅に広げて起動間隔のずれを吸収しつつ、開始直前まで迫った面談には「まもなく始まります」
 *   にすら遅すぎる催促を今さら送らないよう、開始 15 分前で上限を打ち切る)
 * - `eve`: 「翌日に予定されている面談」を対象にする(従来どおり日付のみで判定。時刻では絞らない)。
 *   通知文言が「明日」と明言するため、日付をまたいでからの遅延配信はしない
 *   (日付が変わればその面談は `one_hour_before` 側で拾われる)
 *
 * **二重配信防止(本チケットの肝)**: 送信前に `meeting_reminder_dispatches` へ
 * (面談, 配信窓, 受信者)の組を UNIQUE 制約付きで INSERT する「予約」を試みる。
 * INSERT が成功した場合のみ通知を送る。同時実行 / 再実行でも DB の UNIQUE 制約が
 * 単一の勝者だけを許すため、確実に一度しか送られない(アプリ側のロジックだけに頼らない)。
 *
 * **送信失敗時の再送(レビュー指摘対応)**: 予約行は `notify()` の呼び出しが例外を投げずに完了した
 * 時点(= 二重配信防止用の枠を確保したうえで配信処理を確定させた時点)で `sent_at` を書き込んで
 * 確定する。`notify()` 自体が例外を投げた場合(枠の予約や配信処理の起票そのものに失敗した場合)は
 * 予約行ごと削除し、次回の定期実行で同じ受信者へ再送を試みられるようにする。プロセスが途中で
 * 強制終了する等、削除処理まで辿り着けなかった場合に備え、`sent_at` が未設定のまま一定時間
 * (`STALE_RESERVATION_MINUTES`)経過した予約行は「取りこぼし」とみなして再利用する。
 *
 * **T-A-05 でのキュー非同期化との関係**: `MeetingReminderNotification`(`BusinessEventNotification`
 * 経由)は `ShouldQueueAfterCommit` を実装するため、`notify()` は実際の送信(database 書き込み +
 * mail 送信)を待たず、バックグラウンドジョブとしてキューへ積んだ時点で正常終了する。つまり上記の
 * try/catch が捕捉するのは「キューへの投入自体の失敗」であり、「積んだジョブが worker 側で
 * 送信に失敗するケース」はここでは捕捉されない(捕捉しようにも、その時点では `dispatchTo()` は
 * 既に呼び出し元へ復帰済み)。後者は Laravel のキューが持つ段階的リトライ(`$tries` / `backoff()`)
 * と、上限超過時の `failed_jobs` 記録・`queue:retry` による再投入に委ねる。二重配信防止そのものは
 * 「(面談, 配信窓, 受信者)の組を UNIQUE 制約で一度しか予約させない」という DB 制約に依存しており、
 * 配信処理が同期か非同期かに関わらず有効である。
 */
final class SendMeetingRemindersAction
{
    /** `one_hour_before` の対象を「開始 15 分前」までに打ち切る猶予(分)。 */
    private const ONE_HOUR_BEFORE_GRACE_MINUTES = 15;

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
            // 「開始 15 分前 〜 1 時間前」を対象にする(45 分幅)。コーチ対応可能時間帯は分単位で
            // 登録できるため面談開始時刻も毎時 00 分とは限らず、起動間隔より十分広い幅を持たせることで
            // 起動時刻のずれ・遅延・分単位オフセットのいずれでも取りこぼさない
            // (Kernel 側の起動間隔をこの幅より短くすることで保証する)。
            MeetingReminderWindow::OneHourBefore => $query->whereBetween('scheduled_at', [
                Carbon::now()->addMinutes(self::ONE_HOUR_BEFORE_GRACE_MINUTES),
                Carbon::now()->addHour(),
            ]),
        };
    }
}
