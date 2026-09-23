<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Http\Controllers\MeetingController;
use App\Models\Enrollment;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 予約画面が呼ぶ空き枠取得のユースケース。
 * Enrollment の資格に紐づく空き枠(コーチ側 availability ∩ 未予約)を日付単位で返す。
 *
 * @see MeetingController::fetchAvailability()
 */
final class FetchMeetingAvailabilityAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
    ) {}

    /**
     * @return Collection<int, array{slot_start: Carbon, slot_end: Carbon, available_coach_count: int}>
     */
    public function __invoke(Enrollment $enrollment, Carbon $date): Collection
    {
        return $this->availabilityService->slotsForCertification(
            $enrollment->loadMissing('certification')->certification,
            $date,
        );
    }
}
