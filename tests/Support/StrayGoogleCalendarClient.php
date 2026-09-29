<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\GoogleCalendar\DataTransfer\GoogleCalendarEvent;
use App\Services\GoogleCalendar\DataTransfer\GoogleOAuthToken;
use Carbon\CarbonInterface;
use LogicException;

/**
 * テストの既定で `GoogleCalendarClient` に束縛する「呼ばれたら違反」の実装(T-A-04)。
 *
 * 実装(`GoogleApiCalendarClient`)は Google 公式 SDK 内蔵の Guzzle で通信するため `Http` ファサードの
 * ガードが効かない。テストが `FakeGoogleCalendarClient` へ差し替えずに Google 連携を呼んだら、実通信に
 * 進む前にここで止め、違反として記録する(呼び出し側が例外を握りつぶしても、テストは失敗する)。
 */
final class StrayGoogleCalendarClient implements GoogleCalendarClient
{
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return $this->stray(__FUNCTION__);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): GoogleOAuthToken
    {
        return $this->stray(__FUNCTION__);
    }

    public function refreshAccessToken(string $refreshToken): GoogleOAuthToken
    {
        return $this->stray(__FUNCTION__);
    }

    public function listBusyIntervals(string $accessToken, string $calendarId, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->stray(__FUNCTION__);
    }

    public function createEvent(string $accessToken, string $calendarId, GoogleCalendarEvent $event): string
    {
        return $this->stray(__FUNCTION__);
    }

    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        $this->stray(__FUNCTION__);
    }

    private function stray(string $operation): never
    {
        ExternalRequestGuard::record('google-calendar', $operation);

        throw new LogicException("未モックの Google カレンダー連携({$operation})が呼ばれた。テストで FakeGoogleCalendarClient へ差し替えること。");
    }
}
