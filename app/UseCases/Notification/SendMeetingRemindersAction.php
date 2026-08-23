<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\Services\NotificationRecipientPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 面談リマインダー配信ユースケース(要件シート S7/S9)。
 *
 * 対象は予約済み(reserved)の面談のみ(キャンセル済 / 完了済は対象外)。当事者(受講生 + 担当コーチ)へ、
 * 配信対象の除外規則(`NotificationRecipientPolicy`)を通したうえで配信する。
 *
 * **二重配信防止(本チケットの肝)**: 送信前に `meeting_reminder_dispatches` へ
 * (面談, 配信窓, 受信者)の組を UNIQUE 制約付きで INSERT する「予約」を試みる。
 * INSERT が成功した場合のみ通知を送る。同時実行 / 再実行でも DB の UNIQUE 制約が
 * 単一の勝者だけを許すため、確実に一度しか送られない(アプリ側のロジックだけに頼らない)。
 */
final class SendMeetingRemindersAction
{
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
        if ($recipient === null || ! NotificationRecipientPolicy::eligibleForEventNotification($recipient)) {
            return false;
        }

        try {
            MeetingReminderDispatch::query()->create([
                'meeting_id' => $meeting->id,
                'window' => $window->value,
                'user_id' => $recipient->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // 既に配信済み(重複起動 / 再実行によるスキップ)
            return false;
        }

        try {
            $recipient->notify(new MeetingReminderNotification($meeting, $window));
        } catch (Throwable $e) {
            // 配信失敗はこの受信者だけをスキップし、他の面談 / 受信者の処理は続ける(スコープ外: 自動リトライ)。
            report($e);

            return false;
        }

        return true;
    }

    /**
     * @return Builder<Meeting>
     */
    private function resolveMeetingsQuery(MeetingReminderWindow $window): Builder
    {
        $query = Meeting::query()->where('status', MeetingStatus::Reserved->value);

        return match ($window) {
            // 前日 20:00 に一括配信する運用のため、「翌日」の予約すべてが対象(要件シート S12-05)。
            MeetingReminderWindow::Eve => $query->whereDate('scheduled_at', Carbon::now()->addDay()->toDateString()),
            // 毎時 00 分に起動する運用を前提に、次の 1 時間の開始時刻(概ね 1 時間後)を対象にする。
            // 面談は毎時 00 分スロットのみのため、数分の幅を持たせれば起動時刻のずれを吸収できる。
            MeetingReminderWindow::OneHourBefore => $query->whereBetween('scheduled_at', [
                Carbon::now()->addHour()->subMinutes(2),
                Carbon::now()->addHour()->addMinutes(2),
            ]),
        };
    }
}
