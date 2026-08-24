<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Events\MeetingReserved;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Http\Controllers\MeetingController;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\CoachMeetingLoadService;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 受講生の面談予約申請ユースケース。
 *
 * 残面談回数を確認し、空き枠から過去実績最少のコーチを自動割当して reserved で確定する。
 * 同時刻 race condition は (coach_id, scheduled_at) UNIQUE 違反として検知し
 * MeetingNoAvailableCoachException(→ Controller 側で 409 相当のフラッシュ)へ変換する。
 *
 * 面談回数の消費(ConsumeQuotaAction)と `MeetingReserved` イベント発火を、予約確定と同一の
 * DB トランザクション境界に含める(いずれかが失敗すれば予約自体も成立させない)。
 *
 * S-A-01: 空き確認では連携済コーチの Google カレンダー側の予定も衝突として除外し、予約確定後に
 * Google カレンダーへ予定を自動登録する。Google 通信は DB トランザクションの外で行う。
 *
 * @see MeetingController::store()
 */
final class StoreMeetingAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly CoachMeetingLoadService $coachLoadService,
        private readonly MeetingQuotaService $quotaService,
        private readonly ConsumeQuotaAction $consumeAction,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    public function __invoke(Enrollment $enrollment, Carbon $scheduledAt, string $topic): Meeting
    {
        $student = $enrollment->user;

        // 空き確認(スロット検証・コーチの空き枠抽出)は連携済コーチの数だけ Google と通信しうるため、
        // DB トランザクションの外で行う(トランザクション内に置くと通信が終わるまでロックを保持し、
        // かつこの中でのトークン更新保存が予約失敗時に巻き戻ってしまうため)。
        if ($this->quotaService->remaining($student) < 1) {
            throw new InsufficientMeetingQuotaException;
        }

        $this->availabilityService->validateSlot($enrollment->certification, $scheduledAt);

        $candidates = $this->findAvailableCoaches($enrollment->certification, $scheduledAt);
        if ($candidates->isEmpty()) {
            throw new MeetingNoAvailableCoachException;
        }

        $meeting = DB::transaction(function () use ($enrollment, $student, $scheduledAt, $topic, $candidates) {
            $coach = $this->coachLoadService->leastLoadedCoach($candidates);

            try {
                $meeting = Meeting::create([
                    'enrollment_id' => $enrollment->id,
                    'coach_id' => $coach->id,
                    'student_id' => $student->id,
                    'scheduled_at' => $scheduledAt,
                    'status' => MeetingStatus::Reserved->value,
                    'topic' => $topic,
                    'meeting_url_snapshot' => $coach->meeting_url,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // 同時刻に他受講生が先行予約した race condition: UNIQUE(coach_id, scheduled_at) で弾かれた
                throw new MeetingNoAvailableCoachException($e);
            }

            $transaction = ($this->consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            $meeting = $meeting->fresh();

            event(new MeetingReserved($meeting));

            return $meeting;
        });

        // DB トランザクション確定後に実行(Google 通信の遅延・失敗で予約 COMMIT を長引かせない/巻き戻さない)。
        // 失敗しても GoogleCalendarService 内で catch 済みのため、ここでは常に予約成立として返す。
        $this->googleCalendar->registerMeeting($meeting);

        return $meeting;
    }

    /**
     * 担当コーチ集合のうち、(1) 当該時刻に有効な availability 枠があり、(2) 当該時刻に
     * reserved / completed の Meeting を持たず、(3) 連携済なら Google カレンダー側にも予定が無い
     * コーチ集合を返す。(3) は未連携 / 通信失敗時に GoogleCalendarService が false を返すため、
     * 従来通りの空き判定にフォールバックする。
     *
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        $candidates = $certification->coaches()
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time) {
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get();

        $slotEnd = $scheduledAt->copy()->addHour();

        return $candidates
            ->reject(fn (User $coach) => $this->googleCalendar->hasConflict($coach, $scheduledAt, $slotEnd))
            ->values();
    }
}
