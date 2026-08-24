<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Http\Controllers\MeetingController;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\MeetingQuotaService;

/**
 * 予約画面(受講生・URL に Enrollment を含む正規ルート)の表示に必要なデータを取得するユースケース。
 * 認可(本人所有 / status==learning)は Controller 側で判定済の前提。
 *
 * @see MeetingController::create()
 */
final class CreateFormAction
{
    public function __construct(
        private readonly MeetingQuotaService $meetingQuota,
    ) {}

    /**
     * @return array{enrollment: Enrollment, meetingsRemaining: int}
     */
    public function __invoke(Enrollment $enrollment, User $student): array
    {
        return [
            'enrollment' => $enrollment->loadMissing('certification'),
            'meetingsRemaining' => $this->meetingQuota->remaining($student),
        ];
    }
}
