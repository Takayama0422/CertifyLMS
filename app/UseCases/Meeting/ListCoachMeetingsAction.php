<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Http\Controllers\MeetingController;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * コーチ宛の面談一覧を取得するユースケース。担当受講生 / 受講登録での絞り込みを併せて提供する。
 *
 * upcoming: 次の面談を一番上に置く(昇順) / past・all: 直近の活動を一番上に置く(降順)。
 *
 * @see MeetingController::indexAsCoach()
 */
final class ListCoachMeetingsAction
{
    /**
     * @return LengthAwarePaginator<int, Meeting>
     */
    public function __invoke(
        User $coach,
        string $filter,
        ?string $studentId,
        ?string $enrollmentId,
    ): LengthAwarePaginator {
        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($coach)
            ->when($studentId, fn ($q, $id) => $q->where('student_id', $id))
            ->when($enrollmentId, fn ($q, $id) => $q->where('enrollment_id', $id));

        return match ($filter) {
            'past' => $query->past()->orderByDesc('scheduled_at')->paginate(20),
            'all' => $query->orderByDesc('scheduled_at')->paginate(20),
            default => $query->upcoming()->orderBy('scheduled_at')->paginate(20),
        };
    }
}
