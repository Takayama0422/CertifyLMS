<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Contracts\PaymentGatewayContract;
use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Tests\Support\ExternalRequestGuard;
use Tests\Support\StrayGoogleCalendarClient;
use Tests\Support\StrayPaymentGateway;
use Tests\Support\StrayStripeHttpClient;
use Tests\Support\StrictHttpFactory;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        ExternalRequestGuard::reset();
        $this->installExternalRequestGuards();
    }

    /**
     * モックしていない外部通信(Google / Gemini / Stripe)が発生したらテストを失敗させる(T-A-04)。
     *
     * 外部連携の呼び出し側は通信失敗を握りつぶしてフォールバックするため、例外で止めるだけでは足りない。
     * 各経路で違反を `ExternalRequestGuard` に記録し、{@see self::assertPostConditions()} で 1 件でもあれば失敗とする。
     * 外部通信をモックするテストは、従来どおり `Http::fake()` /
     * `$this->app->instance(GoogleCalendarClient::class, ...)` / `$this->app->instance(PaymentGatewayContract::class, ...)` /
     * `ApiRequestor::setHttpClient(...)` で差し替えればよい(差し替えたものが優先される)。
     */
    private function installExternalRequestGuards(): void
    {
        // Gemini(`Http` ファサード): `Http::fake()` にマッチしない通信は実通信へ流さず、違反として記録する
        $this->app->singleton(HttpFactory::class, fn ($app) => new StrictHttpFactory($app->make(Dispatcher::class)));
        Http::clearResolvedInstance(HttpFactory::class);

        // Google カレンダー(公式 SDK 内蔵の Guzzle で通信するため `Http` のガードが効かない): 契約ごと差し替える
        $this->app->bind(GoogleCalendarClient::class, StrayGoogleCalendarClient::class);

        // Stripe: 決済ゲートウェイの契約と、SDK 自身の HTTP クライアントの両方を塞ぐ
        $this->app->bind(PaymentGatewayContract::class, StrayPaymentGateway::class);
        ApiRequestor::setHttpClient(new StrayStripeHttpClient);
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $violations = ExternalRequestGuard::violations();
        ExternalRequestGuard::reset();

        $this->assertSame(
            [],
            $violations,
            "モックしていない外部通信が発生した。テストで Http::fake() / FakeGoogleCalendarClient / FakePaymentGateway 等へ差し替えること:\n".implode("\n", $violations),
        );
    }
}
