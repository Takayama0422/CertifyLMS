<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\GoogleCalendar\DataTransfer\GoogleCalendarEvent;
use App\Services\GoogleCalendar\DataTransfer\GoogleOAuthToken;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendarApiService;
use Google\Service\Calendar\Event as GoogleApiEvent;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use GuzzleHttp\Client as GuzzleHttpClient;
use RuntimeException;

/**
 * google/apiclient(公式 SDK)を用いた Google Calendar API との実通信実装。
 *
 * `config('services.google.*')` が未設定(空文字)でもインスタンス化自体は失敗しない
 * (`Google\Client` は空のクライアント ID / シークレットを許容する)。実際に外部通信が発生するのは
 * 各 method 呼び出し時のみで、その失敗は呼び出し元の `GoogleCalendarService` が catch して
 * 面談機能の根幹(空き枠表示 / 予約 / キャンセル)を止めないようフォールバックする。
 *
 * このクラス自体は実通信を行うため、テストでは `GoogleCalendarClient` インターフェースを
 * フェイク実装に差し替えて使う(本クラスを直接テストしない = 実通信を発生させない)。
 */
final class GoogleApiCalendarClient implements GoogleCalendarClient
{
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $client = $this->makeClient($redirectUri);
        $client->setState($state);
        $client->setAccessType('offline');
        // 初回だけでなく再連携時も refresh_token を確実に受け取るため毎回同意画面を出す
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        $client->addScope(GoogleCalendarApiService::CALENDAR_EVENTS);
        $client->addScope(GoogleCalendarApiService::CALENDAR_READONLY);

        return $client->createAuthUrl();
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): GoogleOAuthToken
    {
        $client = $this->makeClient($redirectUri);
        $token = $client->fetchAccessTokenWithAuthCode($code);

        return $this->toToken($token, fallbackRefreshToken: null);
    }

    public function refreshAccessToken(string $refreshToken): GoogleOAuthToken
    {
        $client = $this->makeClient();
        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);

        // Google はリフレッシュ応答に refresh_token を含めないことがあるため、既存値を引き継ぐ
        return $this->toToken($token, fallbackRefreshToken: $refreshToken);
    }

    public function listBusyIntervals(string $accessToken, string $calendarId, CarbonInterface $from, CarbonInterface $to): array
    {
        $service = new GoogleCalendarApiService($this->clientWithToken($accessToken));

        $item = new FreeBusyRequestItem;
        $item->setId($calendarId);

        $request = new FreeBusyRequest;
        $request->setTimeMin($from->toRfc3339String());
        $request->setTimeMax($to->toRfc3339String());
        $request->setItems([$item]);

        $response = $service->freebusy->query($request);
        $calendars = $response->getCalendars();
        $busy = $calendars[$calendarId] ?? null;

        if ($busy === null) {
            return [];
        }

        return array_map(
            static fn ($period): array => [
                'start' => Carbon::parse($period->getStart()),
                'end' => Carbon::parse($period->getEnd()),
            ],
            $busy->getBusy(),
        );
    }

    public function createEvent(string $accessToken, string $calendarId, GoogleCalendarEvent $event): string
    {
        $service = new GoogleCalendarApiService($this->clientWithToken($accessToken));

        $apiEvent = new GoogleApiEvent([
            'summary' => $event->summary,
            'description' => $event->description,
            'start' => new EventDateTime(['dateTime' => $event->start->toRfc3339String()]),
            'end' => new EventDateTime(['dateTime' => $event->end->toRfc3339String()]),
        ]);

        $created = $service->events->insert($calendarId, $apiEvent);

        $id = $created->getId();
        if ($id === null || $id === '') {
            throw new RuntimeException('Google Calendar API did not return an event id.');
        }

        return $id;
    }

    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        $service = new GoogleCalendarApiService($this->clientWithToken($accessToken));
        $service->events->delete($calendarId, $eventId);
    }

    private function makeClient(?string $redirectUri = null): GoogleClient
    {
        $client = new GoogleClient;
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        // google/apiclient は既定で接続 / 応答タイムアウトを設定しない(無制限に待つ)ため、
        // 応答が返ってこない失敗(タイムアウト)を必ず有限時間で打ち切るよう明示する。
        // 未設定時は Google\Client::createDefaultHttpClient() 相当の Guzzle クライアントを自前で組み直す形になる。
        $client->setHttpClient($this->makeHttpClient($client));

        if ($redirectUri !== null) {
            $client->setRedirectUri($redirectUri);
        }

        return $client;
    }

    /**
     * 接続 / 応答タイムアウトを設定した Guzzle クライアントを組み立てる。
     * タイムアウト値は環境変数(GOOGLE_CALENDAR_CONNECT_TIMEOUT / GOOGLE_CALENDAR_TIMEOUT)から変更できる。
     * `http_errors => false` は Google\Client::createDefaultHttpClient() の既定を踏襲する
     * (HTTP エラー応答を Guzzle 例外ではなく Google SDK 側の例外として扱わせるため)。
     */
    private function makeHttpClient(GoogleClient $client): GuzzleHttpClient
    {
        return new GuzzleHttpClient([
            'base_uri' => $client->getConfig('base_path'),
            'http_errors' => false,
            'connect_timeout' => (float) config('services.google.connect_timeout'),
            'timeout' => (float) config('services.google.timeout'),
        ]);
    }

    private function clientWithToken(string $accessToken): GoogleClient
    {
        $client = $this->makeClient();
        $client->setAccessToken($accessToken);

        return $client;
    }

    /**
     * @param array<string, mixed> $token
     */
    private function toToken(array $token, ?string $fallbackRefreshToken): GoogleOAuthToken
    {
        if (isset($token['error'])) {
            $description = is_string($token['error_description'] ?? null) ? $token['error_description'] : $token['error'];
            throw new RuntimeException('Google OAuth token exchange failed: '.$description);
        }

        if (! isset($token['access_token']) || ! is_string($token['access_token'])) {
            throw new RuntimeException('Google OAuth response did not contain an access token.');
        }

        $refreshToken = $token['refresh_token'] ?? $fallbackRefreshToken;

        return new GoogleOAuthToken(
            accessToken: $token['access_token'],
            refreshToken: is_string($refreshToken) ? $refreshToken : null,
            expiresAt: Carbon::now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        );
    }
}
