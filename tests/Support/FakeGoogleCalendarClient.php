<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\GoogleCalendar\DataTransfer\GoogleCalendarEvent;
use App\Services\GoogleCalendar\DataTransfer\GoogleOAuthToken;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `GoogleCalendarClient` のテスト用フェイク実装。実通信を一切発生させない。
 *
 * S-A-01 のテスト方針(「外部連携は実通信を発生させない。モックしていない通信が起きたら落ちる」)に沿い、
 * `App\Services\GoogleCalendar\GoogleApiCalendarClient`(実通信の実装)は一切使わず、
 * コンテナへこのフェイクを束縛して `GoogleCalendarService` のフォールバック挙動を検証する。
 *
 * 各 method は accessToken をキーに busy 区間 / 例外を差し替えられる。呼び出し記録(`created` /
 * `deleted` / `authorizationUrlCalls`)でアサーションできる。
 */
final class FakeGoogleCalendarClient implements GoogleCalendarClient
{
    /** @var array<string, array<int, array{start: CarbonInterface, end: CarbonInterface}>> */
    public array $busyIntervalsByToken = [];

    public ?Throwable $busyIntervalsException = null;

    /**
     * 呼び出しを模擬的に遅延させるマイクロ秒数(usleep に渡す)。0 なら即時。
     * 「例外は投げるが応答に時間がかかる」失敗(タイムアウト等)を模した状態を作るために使う
     * (S-A-01 コードレビュー指摘: 即時例外しか検証していなかったため追加)。
     */
    public int $delayMicroseconds = 0;

    public ?Throwable $createEventException = null;

    public ?Throwable $deleteEventException = null;

    public ?Throwable $refreshException = null;

    public ?Throwable $exchangeException = null;

    public string $nextEventId = 'evt_fake_1';

    public string $authorizationUrlResult = 'https://accounts.google.com/o/oauth2/fake-auth';

    public ?GoogleOAuthToken $exchangeResult = null;

    public ?GoogleOAuthToken $refreshResult = null;

    /** @var array<int, array{state: string, redirectUri: string}> */
    public array $authorizationUrlCalls = [];

    /** @var array<int, array{accessToken: string, calendarId: string, event: GoogleCalendarEvent}> */
    public array $createdEvents = [];

    /** @var array<int, array{accessToken: string, calendarId: string, eventId: string}> */
    public array $deletedEvents = [];

    /** @var array<int, array{refreshToken: string}> */
    public array $refreshCalls = [];

    /**
     * 各通信の呼び出し時点の DB トランザクション段数(`DB::transactionLevel()`)の記録。
     * 「Google 通信は予約 / キャンセル確定の DB トランザクションの外で行う」ことを検証するために使う。
     *
     * @var array{busy: array<int, int>, create: array<int, int>, delete: array<int, int>}
     */
    public array $transactionLevels = ['busy' => [], 'create' => [], 'delete' => []];

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $this->authorizationUrlCalls[] = ['state' => $state, 'redirectUri' => $redirectUri];

        return $this->authorizationUrlResult;
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): GoogleOAuthToken
    {
        if ($this->exchangeException !== null) {
            throw $this->exchangeException;
        }

        return $this->exchangeResult ?? new GoogleOAuthToken(
            accessToken: 'access-'.$code,
            refreshToken: 'refresh-'.$code,
            expiresAt: Carbon::now()->addHour(),
        );
    }

    public function refreshAccessToken(string $refreshToken): GoogleOAuthToken
    {
        $this->refreshCalls[] = ['refreshToken' => $refreshToken];

        if ($this->refreshException !== null) {
            throw $this->refreshException;
        }

        return $this->refreshResult ?? new GoogleOAuthToken(
            accessToken: 'refreshed-access-token',
            refreshToken: $refreshToken,
            expiresAt: Carbon::now()->addHour(),
        );
    }

    public function listBusyIntervals(string $accessToken, string $calendarId, CarbonInterface $from, CarbonInterface $to): array
    {
        $this->transactionLevels['busy'][] = DB::transactionLevel();

        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        if ($this->busyIntervalsException !== null) {
            throw $this->busyIntervalsException;
        }

        return $this->busyIntervalsByToken[$accessToken] ?? [];
    }

    public function createEvent(string $accessToken, string $calendarId, GoogleCalendarEvent $event): string
    {
        $this->transactionLevels['create'][] = DB::transactionLevel();

        if ($this->createEventException !== null) {
            throw $this->createEventException;
        }

        $this->createdEvents[] = ['accessToken' => $accessToken, 'calendarId' => $calendarId, 'event' => $event];

        return $this->nextEventId;
    }

    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        $this->transactionLevels['delete'][] = DB::transactionLevel();

        if ($this->deleteEventException !== null) {
            throw $this->deleteEventException;
        }

        $this->deletedEvents[] = ['accessToken' => $accessToken, 'calendarId' => $calendarId, 'eventId' => $eventId];
    }
}
