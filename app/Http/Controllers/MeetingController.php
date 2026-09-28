<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Events\MeetingCanceled;
use App\Events\MeetingReserved;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;
use App\Http\Requests\Meeting\UpsertMemoRequest;
use App\Listeners\SendMeetingPartyNotifications;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\User;
use App\Services\CoachMeetingLoadService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\FetchMeetingAvailabilityAction;
use App\UseCases\Meeting\ListCoachMeetingsAction;
use App\UseCases\Meeting\ListMeetingsAction;
use App\UseCases\Meeting\ShowMeetingAction;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 1on1 面談予約 (Meeting) の HTTP エントリポイント。
 *
 * 受講生側一覧 / コーチ側一覧 / 詳細 / 空き時間取得の 4 経路のみクエリ組み立てを
 * `App\UseCases\Meeting\*` の Action へ委譲する(本チケットの対象範囲)。
 * create / createFallback / store / cancel / upsertMemo は業務ロジック・トランザクション境界を
 * 引き続き Controller 内で扱う(対象範囲外)。
 *
 * 通知発火は Controller の責務に含めない。予約 / キャンセルの成立を `MeetingReserved` /
 * `MeetingCanceled` イベントとして発火するのみで、当事者への配信は `SendMeetingPartyNotifications`
 * リスナーが担う(Seeder・バッチ等、本 Controller 以外の経路から予約 / キャンセルしても通知が飛ぶように)。
 *
 * Google カレンダー連携(S-A-01)も同じイベントに乗る。予定の登録・削除は `RegisterGoogleCalendarEvent` /
 * `DeleteGoogleCalendarEvent`(コミット後に実行)が担う。予約時の空き確認だけは予約確定前に判定が要るため、
 * `store()` が DB トランザクションの外で `MeetingAvailabilityService::googleBusyCoachIds()` を呼ぶ。
 *
 * @see SendMeetingPartyNotifications
 */
class MeetingController extends Controller
{
    /**
     * 受講生本人の面談一覧。filter (upcoming/past/all) クエリで履歴を切り替える。
     */
    public function index(IndexRequest $request, ListMeetingsAction $action): View
    {
        $filter = $request->validated('filter') ?? 'upcoming';

        $result = $action($request->user(), $filter);

        return view('meeting.index', [
            'meetings' => $result['meetings'],
            'filter' => $filter,
            'meetingsRemaining' => $result['meetingsRemaining'],
        ]);
    }

    /**
     * コーチ宛の面談一覧。担当受講生 / 受講登録での絞り込みを併せて提供する。
     */
    public function indexAsCoach(IndexAsCoachRequest $request, ListCoachMeetingsAction $action): View
    {
        $filters = $request->validated();
        $filter = $filters['filter'] ?? 'upcoming';
        $studentId = $filters['student'] ?? null;
        $enrollmentId = $filters['enrollment'] ?? null;

        $meetings = $action($request->user(), $filter, $studentId, $enrollmentId);

        return view('meeting.coach.index', [
            'meetings' => $meetings,
            'filter' => $filter,
            'studentFilter' => $studentId,
            'enrollmentFilter' => $enrollmentId,
        ]);
    }

    /**
     * 面談詳細(当事者共通)。Policy で coach/student の閲覧範囲を絞る。
     */
    public function show(Meeting $meeting, ShowMeetingAction $action): View
    {
        $this->authorize('view', $meeting);

        return view('meeting.show', [
            'meeting' => $action($meeting),
        ]);
    }

    /**
     * 予約画面(受講生): URL に Enrollment を含む正規ルートで表示する。
     */
    public function create(Enrollment $enrollment, MeetingQuotaService $meetingQuota): View
    {
        $this->authorize('create', Meeting::class);

        abort_unless($enrollment->user_id === auth()->id(), 403);
        abort_unless($enrollment->status === EnrollmentStatus::Learning, 403);

        $enrollment->loadMissing('certification');

        return view('meeting.create', [
            'enrollment' => $enrollment,
            'meetingsRemaining' => $meetingQuota->remaining(auth()->user()),
        ]);
    }

    /**
     * 予約画面のエントリポイント(URL に Enrollment 無し)。
     * `resolve-default-enrollment` Middleware が default 資格に redirect するため、
     * 本 method に到達するのは default 未設定 + 残存 Enrollment が 0 件 or 2+ 件のケース。
     */
    public function createFallback(): View
    {
        $user = auth()->user();
        $enrollments = $user
            ?->enrollments()
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->with('certification')
            ->get();

        return view('meeting.empty-state', [
            'enrollments' => $enrollments ?? collect(),
        ]);
    }

    /**
     * 受講生の予約申請。残面談回数を確認し、空き枠から過去実績最少のコーチを自動割当して reserved で確定する。
     * 同時刻 race condition は (coach_id, scheduled_at) UNIQUE 違反として検知し 409 へ変換する。
     *
     * 面談回数の消費(ConsumeQuotaAction)と `MeetingReserved` イベント発火は、予約確定と同一の
     * DB トランザクション境界に含める(いずれかが失敗すれば予約自体も成立させない)。
     */
    public function store(
        Enrollment $enrollment,
        StoreRequest $request,
        MeetingAvailabilityService $availabilityService,
        CoachMeetingLoadService $coachLoadService,
        MeetingQuotaService $quotaService,
        ConsumeQuotaAction $consumeAction,
    ): RedirectResponse {
        $scheduledAt = Carbon::parse($request->validated('scheduled_at'));
        $topic = $request->validated('topic');
        $student = $enrollment->user;

        // 以下 2 つの空き確認は連携済コーチの数だけ Google と通信しうる(S-A-01)ため、DB トランザクションの外で
        // 先に行う。トランザクション内に置くと、通信が終わるまでロックを保持し、トークン更新の保存も予約失敗で
        // 巻き戻る。残数不足の判定を先に置くのは、従来どおり「残数不足 → 枠外」の順でエラーを返すため
        // (残数の確定判定は、競合に強いようトランザクション内でも行う)。
        if ($quotaService->remaining($student) < 1) {
            throw new InsufficientMeetingQuotaException;
        }

        $availabilityService->validateSlot($enrollment->certification, $scheduledAt);

        $googleBusyCoachIds = $availabilityService->googleBusyCoachIds($enrollment->certification, $scheduledAt);

        $meeting = DB::transaction(function () use (
            $enrollment,
            $student,
            $scheduledAt,
            $topic,
            $googleBusyCoachIds,
            $coachLoadService,
            $quotaService,
            $consumeAction,
        ) {
            if ($quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $candidates = $this->findAvailableCoaches($enrollment->certification, $scheduledAt)
                ->reject(fn (User $coach): bool => in_array($coach->id, $googleBusyCoachIds, true))
                ->values();
            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $coachLoadService->leastLoadedCoach($candidates);

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

            $transaction = ($consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            $meeting = $meeting->fresh();

            event(new MeetingReserved($meeting));

            return $meeting;
        });

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談を予約しました。');
    }

    /**
     * 当事者(受講生 or コーチ)による面談キャンセル。
     * reserved かつ開始前のみキャンセル可。消費済の面談回数 1 回分を返却する。
     *
     * 面談回数の返却(RefundQuotaAction)と `MeetingCanceled` イベント発火は、状態遷移と同一の
     * DB トランザクション境界に含める。
     */
    public function cancel(
        Meeting $meeting,
        RefundQuotaAction $refundAction,
    ): RedirectResponse {
        $this->authorize('cancel', $meeting);

        $actor = auth()->user();

        DB::transaction(function () use ($meeting, $actor, $refundAction) {
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

            ($refundAction)($locked->student, $locked->id);

            event(new MeetingCanceled($locked));
        });

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    /**
     * 担当コーチによる面談メモ作成・更新。canceled の面談にはメモを残せない。
     */
    public function upsertMemo(Meeting $meeting, UpsertMemoRequest $request): RedirectResponse
    {
        $body = $request->validated('body');

        DB::transaction(function () use ($meeting, $body) {
            if (! in_array($meeting->status, [MeetingStatus::Reserved, MeetingStatus::Completed], true)) {
                throw MeetingStatusTransitionException::forMemo();
            }

            MeetingMemo::updateOrCreate(
                ['meeting_id' => $meeting->id],
                ['body' => $body],
            );
        });

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談メモを保存しました。');
    }

    /**
     * 予約画面が呼ぶ空き枠取得 JSON エンドポイント。
     */
    public function fetchAvailability(Enrollment $enrollment, AvailabilityRequest $request, FetchMeetingAvailabilityAction $action): JsonResponse
    {
        $date = Carbon::parse($request->validated('date'));
        $slots = $action($enrollment, $date);

        return response()->json([
            'date' => $date->toDateString(),
            'slots' => $slots->map(fn (array $slot) => [
                'slot_start' => $slot['slot_start']->toIso8601String(),
                'slot_end' => $slot['slot_end']->toIso8601String(),
                'available_coach_count' => $slot['available_coach_count'],
            ])->all(),
        ]);
    }

    /**
     * 担当コーチ集合のうち、(1) 当該時刻に有効な availability 枠があり、
     * (2) 当該時刻に reserved / completed の Meeting を持たないコーチ集合を返す。
     *
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
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
    }
}
