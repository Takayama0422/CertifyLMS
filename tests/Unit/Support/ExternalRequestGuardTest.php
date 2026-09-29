<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\GoogleCalendarCredential;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\Contracts\PaymentGatewayContract;
use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\Support\ExternalRequestGuard;
use Tests\Support\FakeGoogleCalendarClient;
use Tests\TestCase;

/**
 * T-A-04「モックしていない外部通信が発生したらテストが失敗する仕組み」自体の検証。
 *
 * 各テストは、意図的に未モックの外部通信を起こして「違反として記録される」ことを確認したあと、
 * `ExternalRequestGuard::reset()` で記録を消す(消さないと、この仕組みが本テスト自身を失敗させる)。
 * 本ファイルのテストは全て、実際には外部へ通信しない(通信の手前で遮断される)。
 */
#[Group('external')]
class ExternalRequestGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unmocked_http_request_is_blocked_and_recorded(): void
    {
        // Http::fake() を 1 度も呼ばない = スタブ未登録。標準の preventStrayRequests() だとここは素通りして実通信になる
        $response = Http::post('https://generativelanguage.googleapis.com/v1beta/models/x:generateContent', ['a' => 1]);

        $this->assertSame(599, $response->status(), '実通信ではなくガードの応答が返る');
        $this->assertSame(
            ['[http] POST https://generativelanguage.googleapis.com/v1beta/models/x:generateContent'],
            ExternalRequestGuard::violations(),
        );

        ExternalRequestGuard::reset();
    }

    public function test_violation_is_recorded_even_when_the_caller_swallows_the_failure(): void
    {
        // 呼び出し側の `catch (Throwable)` で握りつぶされても、記録は残る(=テストは失敗する)
        try {
            $response = Http::get('https://example.com/swallowed');
            if ($response->failed()) {
                throw new LogicException('呼び出し側が失敗として握りつぶす');
            }
        } catch (\Throwable) {
            // フォールバック相当
        }

        $this->assertCount(1, ExternalRequestGuard::violations());

        ExternalRequestGuard::reset();
    }

    public function test_request_matching_a_fake_is_not_a_violation(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $response = Http::get('https://example.com/mocked');

        $this->assertSame(200, $response->status());
        $this->assertSame([], ExternalRequestGuard::violations());
    }

    public function test_only_the_unmatched_request_is_a_violation_when_some_urls_are_faked(): void
    {
        Http::fake(['https://mocked.example.com/*' => Http::response(['ok' => true], 200)]);

        Http::get('https://mocked.example.com/a');
        Http::get('https://unmocked.example.com/b');

        $this->assertSame(['[http] GET https://unmocked.example.com/b'], ExternalRequestGuard::violations());

        ExternalRequestGuard::reset();
    }

    public function test_unmocked_google_calendar_client_is_blocked_and_recorded_even_through_the_fallback(): void
    {
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create([
            'token_expires_at' => Carbon::now()->addHour(),
        ]);

        // GoogleCalendarService は通信失敗を握りつぶして空を返す(面談機能を止めないため)。それでも違反は記録される
        $busy = app(GoogleCalendarService::class)->busyIntervals($coach->fresh(), Carbon::now(), Carbon::now()->addDay());

        $this->assertCount(0, $busy);
        $this->assertSame(['[google-calendar] listBusyIntervals'], ExternalRequestGuard::violations());

        ExternalRequestGuard::reset();
    }

    public function test_unmocked_payment_gateway_is_blocked_and_recorded(): void
    {
        try {
            app(PaymentGatewayContract::class)->createCheckoutSession(
                MeetingPack::factory()->published()->create(),
                Payment::factory()->create(),
                'https://example.com/success',
                'https://example.com/cancel',
            );
            $this->fail('未モックの決済ゲートウェイ呼び出しが通ってしまった');
        } catch (LogicException) {
            // 期待どおり遮断
        }

        $this->assertSame(['[stripe] createCheckoutSession'], ExternalRequestGuard::violations());

        ExternalRequestGuard::reset();
    }

    public function test_unmocked_stripe_sdk_call_is_blocked_and_recorded(): void
    {
        try {
            (new StripeClient('sk_test_dummy'))->checkout->sessions->create(['mode' => 'payment']);
            $this->fail('未モックの Stripe SDK 通信が通ってしまった');
        } catch (ApiConnectionException) {
            // 期待どおり遮断(SDK の通信層で止まる)
        }

        $violations = ExternalRequestGuard::violations();
        $this->assertCount(1, $violations);
        $this->assertStringStartsWith('[stripe-sdk] POST https://api.stripe.com/', $violations[0]);

        ExternalRequestGuard::reset();
    }

    public function test_replacing_the_client_with_a_fake_is_not_a_violation(): void
    {
        $fake = new FakeGoogleCalendarClient;
        $this->app->instance(GoogleCalendarClient::class, $fake);
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create([
            'token_expires_at' => Carbon::now()->addHour(),
        ]);

        app(GoogleCalendarService::class)->busyIntervals($coach->fresh(), Carbon::now(), Carbon::now()->addDay());

        $this->assertSame([], ExternalRequestGuard::violations());
    }
}
