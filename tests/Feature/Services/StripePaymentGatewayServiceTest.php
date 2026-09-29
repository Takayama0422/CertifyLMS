<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\Payment\PaymentGatewayUnavailableException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Services\Payment\CheckoutSession;
use App\Services\StripePaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * T-A-04: `StripePaymentGatewayService`(Stripe SDK でチェックアウトセッションを作る層)のモックテスト。
 *
 * モック手法: Stripe SDK は独自の HTTP クライアントで通信するため、Laravel の `Http::fake()` は効かない。
 * SDK が標準で用意している差し替え口(`ApiRequestor::setHttpClient()`)へ `FakeStripeHttpClient` を差し込み、
 * SDK が組み立てたリクエストを記録して検証する(実通信は発生しない)。
 * 利用側(`CreateCheckoutSessionAction` 等)のテストは、これとは別に `FakePaymentGateway` で契約ごと差し替えて行っている。
 */
#[Group('external')]
#[Group('stripe')]
class StripePaymentGatewayServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_gateway']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stripeReplies(array $body, int $status = 200): FakeStripeHttpClient
    {
        $client = new FakeStripeHttpClient($body, $status);
        ApiRequestor::setHttpClient($client);

        return $client;
    }

    private function createSession(): CheckoutSession
    {
        $plan = MeetingPack::factory()->published()->create(['name' => '追加面談 5 回パック']);
        $payment = Payment::factory()->pending()->create(['amount' => 12000, 'quantity' => 5]);

        return (new StripePaymentGatewayService)->createCheckoutSession($plan, $payment, 'https://lms.example.com/ok', 'https://lms.example.com/ng');
    }

    public function test_it_creates_a_checkout_session_with_the_expected_request(): void
    {
        $client = $this->stripeReplies(['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']);

        $session = $this->createSession();

        $this->assertSame('cs_test_1', $session->id);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $session->url);

        $this->assertCount(1, $client->requests);
        $request = $client->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $request['url']);
        $this->assertContains('Authorization: Bearer sk_test_gateway', $request['headers'], 'シークレットキーは環境変数の値を使う');
        $params = $request['params'];
        $this->assertSame('payment', $params['mode'], '都度購入のみ');
        $this->assertSame('https://lms.example.com/ok', $params['success_url']);
        $this->assertSame('https://lms.example.com/ng', $params['cancel_url']);
        $payment = Payment::sole();
        $this->assertSame($payment->id, $params['client_reference_id']);
        $this->assertSame($payment->id, $params['metadata']['payment_id'], 'Webhook で決済を特定するための紐付け');
        $item = $params['line_items'][0];
        $this->assertSame(1, $item['quantity']);
        $this->assertSame('jpy', $item['price_data']['currency'], '円のみ');
        $this->assertSame(12000, $item['price_data']['unit_amount'], '金額は購入時点の Payment のスナップショット');
        $this->assertSame('追加面談 5 回パック', $item['price_data']['product_data']['name']);
    }

    public function test_it_does_not_call_stripe_when_the_secret_key_is_not_configured(): void
    {
        config(['services.stripe.secret' => '']);
        $client = $this->stripeReplies([]);

        try {
            $this->createSession();
            $this->fail('鍵未設定なのに例外にならなかった');
        } catch (PaymentGatewayUnavailableException) {
            // 期待どおり
        }

        $this->assertSame([], $client->requests, '鍵が無ければ Stripe へ通信しない');
    }

    public function test_an_api_error_response_becomes_a_gateway_unavailable_exception(): void
    {
        $this->stripeReplies(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']], 401);

        $this->expectException(PaymentGatewayUnavailableException::class);

        $this->createSession();
    }

    public function test_a_connection_failure_becomes_a_gateway_unavailable_exception(): void
    {
        ApiRequestor::setHttpClient(new FakeStripeHttpClient(exception: new ApiConnectionException('Could not connect to Stripe')));

        $this->expectException(PaymentGatewayUnavailableException::class);

        $this->createSession();
    }

    public function test_a_response_without_a_checkout_url_becomes_a_gateway_unavailable_exception(): void
    {
        $this->stripeReplies(['id' => 'cs_test_no_url', 'object' => 'checkout.session']);

        $this->expectException(PaymentGatewayUnavailableException::class);

        $this->createSession();
    }
}
