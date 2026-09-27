<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Http\Controllers\MeetingController;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 受講生本人の面談一覧を取得するユースケース。
 *
 * filter (upcoming/past/all) で履歴を切り替え、あわせて残面談回数も返す
 * (画面が「残り◯回」の表示を面談一覧と同時に必要とするため)。
 *
 * @see MeetingController::index()
 */
final class ListMeetingsAction
{
    public function __construct(
        private readonly MeetingQuotaService $meetingQuota,
    ) {}

    /**
     * @return array{meetings: LengthAwarePaginator<int, Meeting>, meetingsRemaining: int}
     */
    public function __invoke(User $student, string $filter): array
    {
        $query = Meeting::query()
            ->with(['enrollment.certification', 'coach'])
            ->forStudent($student)
            ->orderByDesc('scheduled_at');

        $meetings = match ($filter) {
            'past' => $query->past()->paginate(20),
            'all' => $query->paginate(20),
            default => $query->upcoming()->paginate(20),
        };

        return [
            'meetings' => $meetings,
            'meetingsRemaining' => $this->meetingQuota->remaining($student),
        ];
    }
}
