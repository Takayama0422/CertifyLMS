<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Http\Controllers\MeetingController;
use App\Models\Meeting;

/**
 * 面談詳細(当事者共通)の表示に必要な関連を Eager Loading して返すユースケース。
 * 閲覧範囲の絞り込みは Policy(MeetingPolicy::view)が担う。
 *
 * @see MeetingController::show()
 */
final class ShowMeetingAction
{
    public function __invoke(Meeting $meeting): Meeting
    {
        return $meeting->loadMissing([
            'enrollment.certification',
            'coach',
            'student',
            'canceledBy',
            'meetingMemo',
        ]);
    }
}
