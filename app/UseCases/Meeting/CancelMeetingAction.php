<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Events\MeetingCanceled;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Http\Controllers\MeetingController;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;

/**
 * 当事者(受講生 or コーチ)による面談キャンセルユースケース。
 * reserved かつ開始前のみキャンセル可。消費済の面談回数 1 回分を返却する。
 *
 * 面談回数の返却(RefundQuotaAction)と `MeetingCanceled` イベント発火を、状態遷移と同一の
 * DB トランザクション境界に含める。
 *
 * S-A-01: 状態遷移の確定後、Google カレンダーに登録済みの予定があれば削除する。
 * Google 通信は DB トランザクションの外で行う(通信失敗でキャンセル成立を巻き戻さない)。
 *
 * @see MeetingController::cancel()
 */
final class CancelMeetingAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    public function __invoke(Meeting $meeting, User $actor): Meeting
    {
        DB::transaction(function () use ($meeting, $actor) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            ($this->refundAction)($locked->student, $locked->id);

            event(new MeetingCanceled($locked));
        });

        $canceled = $meeting->fresh() ?? $meeting;

        // DB トランザクション確定後に実行(Google 通信の失敗でキャンセル成立を巻き戻さない)。
        // 未連携 / 未登録 / 通信失敗はすべて GoogleCalendarService 内で catch 済みの no-op。
        $this->googleCalendar->cancelMeeting($canceled);

        return $canceled;
    }
}
