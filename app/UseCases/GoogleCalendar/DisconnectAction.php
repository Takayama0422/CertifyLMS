<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarService;

/**
 * コーチ本人の Google カレンダー連携を解除するユースケース。
 *
 * Google 側のイベントは削除しない(既存の予約は LMS 内には残る仕様。tab-meeting.blade.php の案内文と対応)。
 */
final class DisconnectAction
{
    public function __construct(
        private readonly GoogleCalendarService $service,
    ) {}

    public function __invoke(User $coach): void
    {
        $this->service->disconnect($coach);
    }
}
