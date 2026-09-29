<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar\Contracts;

use App\Services\GoogleCalendar\DataTransfer\GoogleCalendarEvent;
use App\Services\GoogleCalendar\DataTransfer\GoogleOAuthToken;
use Carbon\CarbonInterface;

/**
 * Google Calendar API との実通信を担う窓口。
 *
 * 実装は `App\Services\GoogleCalendar\GoogleApiCalendarClient`(google/apiclient を使用)を
 * `AppServiceProvider` でこのインターフェースに束縛する。テストではこのインターフェースを
 * フェイク実装 or Mockery でモックし、実通信を一切発生させない。
 *
 * 呼び出し元(`GoogleCalendarService`)がすべての例外を捕捉してフォールバックする前提のため、
 * 実装は通信失敗時に素直に例外を投げてよい(隠蔽しない)。
 */
interface GoogleCalendarClient
{
    /**
     * OAuth 認可 URL を生成する。
     */
    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * コールバックで受け取った認可コードをアクセストークン / リフレッシュトークンに交換する。
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): GoogleOAuthToken;

    /**
     * リフレッシュトークンを使ってアクセストークンを再発行する。
     */
    public function refreshAccessToken(string $refreshToken): GoogleOAuthToken;

    /**
     * 指定期間内の busy(予定あり)区間一覧を返す。
     *
     * @return array<int, array{start: CarbonInterface, end: CarbonInterface}>
     */
    public function listBusyIntervals(string $accessToken, string $calendarId, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * 予定を 1 件登録し、Google 側のイベント ID を返す。
     */
    public function createEvent(string $accessToken, string $calendarId, GoogleCalendarEvent $event): string;

    /**
     * 予定を 1 件削除する。
     */
    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void;
}
