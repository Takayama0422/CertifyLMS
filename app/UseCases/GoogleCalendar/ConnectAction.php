<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Exceptions\GoogleCalendar\GoogleCalendarStateMismatchException;
use App\Models\GoogleCalendarCredential;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarService;

/**
 * OAuth コールバックを受けてコーチの Google カレンダー連携を確定させるユースケース。
 *
 * state 検証(なりすまし拒否)とトークン交換は Service 側に委譲し、本 Action は入出力の受け渡しに徹する。
 */
final class ConnectAction
{
    public function __construct(
        private readonly GoogleCalendarService $service,
    ) {}

    /**
     * @throws GoogleCalendarStateMismatchException
     */
    public function __invoke(User $coach, string $code, string $state, string $redirectUri): GoogleCalendarCredential
    {
        return $this->service->connect($coach, $code, $state, $redirectUri);
    }
}
