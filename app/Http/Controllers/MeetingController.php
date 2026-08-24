<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Http\Requests\Meeting\AvailabilityRequest;
use App\Http\Requests\Meeting\IndexAsCoachRequest;
use App\Http\Requests\Meeting\IndexRequest;
use App\Http\Requests\Meeting\StoreRequest;
use App\Http\Requests\Meeting\UpsertMemoRequest;
use App\Listeners\SendMeetingPartyNotifications;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\UseCases\Meeting\CancelMeetingAction;
use App\UseCases\Meeting\CreateFallbackAction;
use App\UseCases\Meeting\CreateFormAction;
use App\UseCases\Meeting\FetchMeetingAvailabilityAction;
use App\UseCases\Meeting\ListCoachMeetingsAction;
use App\UseCases\Meeting\ListMeetingsAction;
use App\UseCases\Meeting\ShowMeetingAction;
use App\UseCases\Meeting\StoreMeetingAction;
use App\UseCases\Meeting\UpsertMeetingMemoAction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 1on1 面談予約 (Meeting) の HTTP エントリポイント。
 *
 * 受講生視点(index / show / create / store / cancel / fetchAvailability)とコーチ視点
 * (indexAsCoach / upsertMemo)を 1 Controller に集約する。各 method はリクエスト受付 / 認可委譲 /
 * レスポンス整形のみを担い、業務ロジック・クエリ組み立ては `App\UseCases\Meeting\*` の
 * Action クラスへ委譲する。
 *
 * 通知発火は Controller の責務に含めない。予約 / キャンセルの成立を `MeetingReserved` /
 * `MeetingCanceled` イベントとして発火するのみで、当事者への配信は `SendMeetingPartyNotifications`
 * リスナーが担う(Seeder・バッチ等、本 Controller 以外の経路から予約 / キャンセルしても通知が飛ぶように)。
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
    public function create(Enrollment $enrollment, CreateFormAction $action): View
    {
        $this->authorize('create', Meeting::class);

        abort_unless($enrollment->user_id === auth()->id(), 403);
        abort_unless($enrollment->status === EnrollmentStatus::Learning, 403);

        return view('meeting.create', $action($enrollment, auth()->user()));
    }

    /**
     * 予約画面のエントリポイント(URL に Enrollment 無し)。
     * `resolve-default-enrollment` Middleware が default 資格に redirect するため、
     * 本 method に到達するのは default 未設定 + 残存 Enrollment が 0 件 or 2+ 件のケース。
     */
    public function createFallback(CreateFallbackAction $action): View
    {
        return view('meeting.empty-state', [
            'enrollments' => $action(auth()->user()),
        ]);
    }

    /**
     * 受講生の予約申請。残面談回数を確認し、空き枠から過去実績最少のコーチを自動割当して reserved で確定する。
     * 同時刻 race condition は (coach_id, scheduled_at) UNIQUE 違反として検知し 409 へ変換する。
     */
    public function store(Enrollment $enrollment, StoreRequest $request, StoreMeetingAction $action): RedirectResponse
    {
        $meeting = $action(
            $enrollment,
            Carbon::parse($request->validated('scheduled_at')),
            $request->validated('topic'),
        );

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談を予約しました。');
    }

    /**
     * 当事者(受講生 or コーチ)による面談キャンセル。
     * reserved かつ開始前のみキャンセル可。消費済の面談回数 1 回分を返却する。
     */
    public function cancel(Meeting $meeting, CancelMeetingAction $action): RedirectResponse
    {
        $this->authorize('cancel', $meeting);

        $action($meeting, auth()->user());

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('success', '面談をキャンセルしました。面談回数を返却しました。');
    }

    /**
     * 担当コーチによる面談メモ作成・更新。canceled の面談にはメモを残せない。
     */
    public function upsertMemo(Meeting $meeting, UpsertMemoRequest $request, UpsertMeetingMemoAction $action): RedirectResponse
    {
        $action($meeting, $request->validated('body'));

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
}
