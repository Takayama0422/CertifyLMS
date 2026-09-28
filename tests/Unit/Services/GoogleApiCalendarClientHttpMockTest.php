<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\GoogleCalendar\DataTransfer\GoogleCalendarEvent;
use App\Services\GoogleCalendar\GoogleApiCalendarClient;
use Carbon\Carbon;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * T-A-04: `GoogleApiCalendarClient`(Google 公式 SDK の応答を DTO へ写す層)のモックテスト。
 *
 * モック手法: この層は SDK 内蔵の Guzzle で通信するため、Laravel の `Http::fake()` は使えない。
 * コンストラクタで差し込める Guzzle の `MockHandler` へ応答を積み、実際に送られたリクエスト
 * (URL / ヘッダ / 本文)を `Middleware::history` で記録して検証する(実通信は発生しない)。
 * 利用側(`GoogleCalendarService` 等)のテストは、これとは別に `FakeGoogleCalendarClient` で
 * カレンダー操作のまとまった単位を差し替えて行っている。
 */
#[Group('external')]
#[Group('google-calendar')]
class GoogleApiCalendarClientHttpMockTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * @param array<int, Response> $responses
     */
    private function clientReplying(array $responses): GoogleApiCalendarClient
    {
        $this->history = [];
        $mock = new MockHandler($responses);

        // 記録は、最下層(実際に送信する直前)のハンドラで行う。Guzzle は後から push したミドルウェアほど
        // 内側になるため、`Middleware::history` を push しておくと、SDK が後から足す認証ミドルウェア
        // (Authorization ヘッダの付与)より外側で記録してしまい、最終的なリクエストを検証できない。
        $recordingHandler = function (RequestInterface $request, array $options) use ($mock) {
            $this->history[] = ['request' => $request];

            return $mock($request, $options);
        };

        return new GoogleApiCalendarClient(HandlerStack::create($recordingHandler));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRequestJson(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'client-id-123', 'services.google.client_secret' => 'client-secret-456']);
    }

    // --- 認可フロー: 連携の開始(認可 URL) ---

    public function test_authorization_url_carries_state_scopes_and_offline_consent(): void
    {
        $url = $this->clientReplying([])->authorizationUrl('state-abc', 'https://lms.example.com/settings/google-calendar/callback');

        $this->assertStringStartsWith('https://accounts.google.com/', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('client-id-123', $query['client_id']);
        $this->assertSame('https://lms.example.com/settings/google-calendar/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('state-abc', $query['state'], 'なりすまし対策の state が含まれる');
        $this->assertSame('offline', $query['access_type'], 'refresh_token を受け取るため offline');
        $this->assertSame('consent', $query['prompt'], '再連携でも refresh_token を確実に受け取るため毎回同意画面を出す');
        $this->assertStringContainsString('auth/calendar.events', $query['scope']);
        $this->assertStringContainsString('auth/calendar.readonly', $query['scope']);
        $this->assertSame([], $this->history, '認可 URL の組み立てでは通信しない');
    }

    // --- 認可フロー: 連携情報の交換 ---

    public function test_exchange_authorization_code_posts_code_and_maps_the_token(): void
    {
        $client = $this->clientReplying([$this->jsonResponse([
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600, 'token_type' => 'Bearer',
        ])]);

        $token = $client->exchangeAuthorizationCode('auth-code-xyz', 'https://lms.example.com/cb');

        $this->assertSame('access-1', $token->accessToken);
        $this->assertSame('refresh-1', $token->refreshToken);
        $this->assertEqualsWithDelta(Carbon::now()->addHour()->timestamp, $token->expiresAt->timestamp, 5);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://oauth2.googleapis.com/token', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame('auth-code-xyz', $form['code']);
        $this->assertSame('https://lms.example.com/cb', $form['redirect_uri']);
        $this->assertSame('client-id-123', $form['client_id']);
        $this->assertSame('client-secret-456', $form['client_secret']);
    }

    public function test_exchange_error_response_becomes_an_exception(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)]);

        try {
            $client->exchangeAuthorizationCode('used-code', 'https://lms.example.com/cb');
            $this->fail('連携情報の交換の失敗が例外にならなかった');
        } catch (Throwable $e) {
            $this->assertStringContainsString('Bad Request', $e->getMessage());
        }
    }

    public function test_exchange_response_without_an_access_token_becomes_an_exception(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['token_type' => 'Bearer', 'expires_in' => 3600])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('access token');

        $client->exchangeAuthorizationCode('code', 'https://lms.example.com/cb');
    }

    // --- 連携の期限切れ → リフレッシュ ---

    public function test_refresh_keeps_the_existing_refresh_token_when_google_omits_it(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['access_token' => 'access-2', 'expires_in' => 3599, 'token_type' => 'Bearer'])]);

        $token = $client->refreshAccessToken('refresh-kept');

        $this->assertSame('access-2', $token->accessToken);
        $this->assertSame('refresh-kept', $token->refreshToken, 'Google はリフレッシュ応答に refresh_token を含めないことがあるため既存値を引き継ぐ');
        parse_str((string) $this->lastRequest()->getBody(), $form);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('refresh-kept', $form['refresh_token']);
    }

    public function test_refresh_uses_the_new_refresh_token_when_google_returns_one(): void
    {
        $client = $this->clientReplying([$this->jsonResponse([
            'access_token' => 'access-3', 'refresh_token' => 'refresh-rotated', 'expires_in' => 3600, 'token_type' => 'Bearer',
        ])]);

        $token = $client->refreshAccessToken('refresh-old');

        $this->assertSame('refresh-rotated', $token->refreshToken);
    }

    public function test_refresh_failure_becomes_an_exception(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

        try {
            $client->refreshAccessToken('revoked-refresh');
            $this->fail('リフレッシュの失敗が例外にならなかった(呼び出し側がフォールバックできない)');
        } catch (Throwable $e) {
            $this->assertStringContainsString('expired or revoked', $e->getMessage());
        }
    }

    // --- カレンダー操作: 空き時刻の取得 ---

    public function test_list_busy_intervals_sends_the_range_and_maps_the_periods(): void
    {
        $from = Carbon::parse('2026-10-05 00:00:00', 'Asia/Tokyo');
        $to = Carbon::parse('2026-10-06 00:00:00', 'Asia/Tokyo');
        $client = $this->clientReplying([$this->jsonResponse(['calendars' => ['primary' => ['busy' => [
            ['start' => '2026-10-05T10:00:00+09:00', 'end' => '2026-10-05T11:00:00+09:00'],
            ['start' => '2026-10-05T15:30:00+09:00', 'end' => '2026-10-05T16:00:00+09:00'],
        ]]]])]);

        $busy = $client->listBusyIntervals('access-token-1', 'primary', $from, $to);

        $this->assertCount(2, $busy);
        $this->assertTrue($busy[0]['start']->equalTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Tokyo')));
        $this->assertTrue($busy[1]['end']->equalTo(Carbon::parse('2026-10-05 16:00:00', 'Asia/Tokyo')));

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/calendar/v3/freeBusy', $request->getUri()->getPath());
        $this->assertSame('Bearer access-token-1', $request->getHeaderLine('Authorization'));
        $body = $this->lastRequestJson();
        $this->assertSame([['id' => 'primary']], $body['items']);
        $this->assertTrue(Carbon::parse($body['timeMin'])->equalTo($from));
        $this->assertTrue(Carbon::parse($body['timeMax'])->equalTo($to));
    }

    public function test_list_busy_intervals_is_empty_when_the_calendar_has_no_busy_period(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['calendars' => ['primary' => ['busy' => []]]])]);

        $this->assertSame([], $client->listBusyIntervals('t', 'primary', Carbon::now(), Carbon::now()->addDay()));
    }

    public function test_list_busy_intervals_is_empty_when_the_response_lacks_the_calendar(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['calendars' => []])]);

        $this->assertSame([], $client->listBusyIntervals('t', 'primary', Carbon::now(), Carbon::now()->addDay()));
    }

    public function test_list_busy_intervals_error_becomes_an_exception(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401)]);

        $this->expectException(Throwable::class);

        $client->listBusyIntervals('bad-token', 'primary', Carbon::now(), Carbon::now()->addDay());
    }

    // --- カレンダー操作: 予定の作成 ---

    public function test_create_event_sends_the_payload_and_returns_the_event_id(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['id' => 'evt_google_1', 'status' => 'confirmed'])]);
        $start = Carbon::parse('2026-10-05 10:00:00', 'Asia/Tokyo');

        $id = $client->createEvent('access-token-2', 'primary', new GoogleCalendarEvent(
            summary: '面談: 学習相談',
            description: 'https://meet.example.com/coach-room',
            start: $start,
            end: $start->copy()->addHour(),
        ));

        $this->assertSame('evt_google_1', $id);
        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/calendar/v3/calendars/primary/events', $request->getUri()->getPath());
        $this->assertSame('Bearer access-token-2', $request->getHeaderLine('Authorization'));
        $body = $this->lastRequestJson();
        $this->assertSame('面談: 学習相談', $body['summary']);
        $this->assertSame('https://meet.example.com/coach-room', $body['description'], '予約時点で固定した面談 URL を焼き込む');
        $this->assertTrue(Carbon::parse($body['start']['dateTime'])->equalTo($start));
        $this->assertTrue(Carbon::parse($body['end']['dateTime'])->equalTo($start->copy()->addHour()));
    }

    public function test_create_event_without_an_id_becomes_an_exception(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['status' => 'confirmed'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return an event id');

        $client->createEvent('t', 'primary', new GoogleCalendarEvent('s', null, Carbon::now(), Carbon::now()->addHour()));
    }

    public function test_create_event_error_becomes_an_exception(): void
    {
        $client = $this->clientReplying([$this->jsonResponse(['error' => ['code' => 403, 'message' => 'Forbidden']], 403)]);

        $this->expectException(Throwable::class);

        $client->createEvent('t', 'primary', new GoogleCalendarEvent('s', null, Carbon::now(), Carbon::now()->addHour()));
    }

    // --- カレンダー操作: 予定の削除 / 削除済み予定の扱い ---

    public function test_delete_event_calls_the_delete_endpoint(): void
    {
        $client = $this->clientReplying([new Response(204)]);

        $client->deleteEvent('access-token-3', 'primary', 'evt_google_9');

        $request = $this->lastRequest();
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertStringEndsWith('/calendar/v3/calendars/primary/events/evt_google_9', $request->getUri()->getPath());
        $this->assertSame('Bearer access-token-3', $request->getHeaderLine('Authorization'));
    }

    public function test_deleting_an_already_deleted_event_surfaces_the_google_error(): void
    {
        // Google 側で既に削除済みの予定は 410(または 404)。このクラスは例外として上へ伝え、
        // 面談のキャンセル自体を止めない判断は呼び出し側(GoogleCalendarService)で行う。
        $client = $this->clientReplying([$this->jsonResponse(['error' => ['code' => 410, 'message' => 'Resource has been deleted']], 410)]);

        try {
            $client->deleteEvent('t', 'primary', 'evt_gone');
            $this->fail('削除済み予定の削除の失敗が例外にならなかった');
        } catch (Throwable $e) {
            $this->assertSame(410, $e->getCode());
        }
    }
}
