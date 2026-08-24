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
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;

/**
 * 当事者(受講生 or コーチ)による面談キャンセルユースケース。
 * reserved かつ開始前のみキャンセル可。
 *
 * `MeetingCanceled` イベント発火を、状態遷移と同一の DB トランザクション境界に含める。
 *
 * NOTE: コンストラクタで RefundQuotaAction を受け取るが、抽出元の Controller 実装も
 * 本 Action と同様にこれを呼び出していなかった(面談回数の返却は実際には行われていなかった)。
 * 本チケットは振る舞いを変えない構造移動のみが対象のため、既存の(不具合に見える)挙動を
 * そのまま温存している。挙動修正は別チケットの判断に委ねる。
 *
 * @see MeetingController::cancel()
 */
final class CancelMeetingAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
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

            event(new MeetingCanceled($locked));
        });

        return $meeting->fresh();
    }
}
