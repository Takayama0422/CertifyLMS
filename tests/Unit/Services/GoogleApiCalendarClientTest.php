<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\GoogleCalendar\GoogleApiCalendarClient;
use GuzzleHttp\Client as GuzzleHttpClient;
use ReflectionClass;
use Tests\TestCase;

/**
 * S-A-01 コードレビュー指摘対応: `GoogleApiCalendarClient` が接続 / 応答タイムアウトを設定した
 * HTTP クライアントを組み立てていることを検証する。
 *
 * このクラス自体は実通信を行う実装であり(クラス自身の docblock 通りテストでは実通信を発生させない方針)、
 * ここでは実際に HTTP リクエストは送らない。内部で組み立てられる Guzzle クライアントの設定値のみを
 * reflection で覗き見て、`services.google.connect_timeout` / `services.google.timeout` が反映されていること、
 * 環境変数(config)から変更できることを検証する。
 *
 * 「遅延したときにフォールバックが働くか」自体は GoogleCalendarServiceTest 側
 * (`test_busy_intervals_falls_back_to_empty_when_client_throws_after_delay` 等)で、
 * 実通信を経由しない FakeGoogleCalendarClient の模擬遅延を使って検証している。
 */
class GoogleApiCalendarClientTest extends TestCase
{
    /**
     * @return array{connect_timeout: mixed, timeout: mixed}
     */
    private function guzzleConfigFor(GoogleApiCalendarClient $client): array
    {
        $reflection = new ReflectionClass($client);
        $makeClient = $reflection->getMethod('makeClient');
        $makeClient->setAccessible(true);
        $googleClient = $makeClient->invoke($client);

        $httpClient = $googleClient->getHttpClient();
        $this->assertInstanceOf(GuzzleHttpClient::class, $httpClient);

        return [
            'connect_timeout' => $httpClient->getConfig('connect_timeout'),
            'timeout' => $httpClient->getConfig('timeout'),
        ];
    }

    public function test_configured_timeouts_are_applied_to_http_client(): void
    {
        config(['services.google.connect_timeout' => 5.0, 'services.google.timeout' => 10.0]);

        $config = $this->guzzleConfigFor(new GoogleApiCalendarClient);

        $this->assertSame(5.0, $config['connect_timeout']);
        $this->assertSame(10.0, $config['timeout']);
    }

    public function test_timeouts_are_configurable_via_env_backed_config(): void
    {
        // .env.example の GOOGLE_CALENDAR_CONNECT_TIMEOUT / GOOGLE_CALENDAR_TIMEOUT を通じて
        // config/services.php の値が変わることを想定した検証(ここでは config() で直接値を差し替える)。
        config(['services.google.connect_timeout' => 1.5, 'services.google.timeout' => 3.0]);

        $config = $this->guzzleConfigFor(new GoogleApiCalendarClient);

        $this->assertSame(1.5, $config['connect_timeout']);
        $this->assertSame(3.0, $config['timeout']);
    }

    public function test_http_client_never_waits_indefinitely_by_default(): void
    {
        // config/services.php の既定値(未設定時 5 秒 / 10 秒)がそのまま反映されること。
        // google/apiclient は既定でタイムアウトを設定しないため、0 や null のままでは
        // 応答が返ってこない失敗を有限時間で打ち切れない(このテストが失敗した場合は退行)。
        $config = $this->guzzleConfigFor(new GoogleApiCalendarClient);

        $this->assertNotNull($config['connect_timeout']);
        $this->assertNotNull($config['timeout']);
        $this->assertGreaterThan(0.0, (float) $config['connect_timeout']);
        $this->assertGreaterThan(0.0, (float) $config['timeout']);
    }
}
