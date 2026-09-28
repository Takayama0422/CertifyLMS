<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Webhooks;

use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\StripeWebhookTestHelpers;
use Tests\TestCase;

/**
 * POST /webhooks/stripe(認証なし、署名検証のみで正当性を担保する公開エンドポイント)。
 *
 * 正規署名 / 不正署名 / 署名欠落の 3 パターンと、鍵未設定時の安全側フォールバック(503)、
 * 重複通知に対する冪等性を HTTP レイヤーで通しで検証する。
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;
    use StripeWebhookTestHelpers;

    private const WEBHOOK_SECRET = 'whsec_test_secret_for_ci';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    private function postWebhook(string $payload, ?string $signatureHeader): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signatureHeader !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $signatureHeader;
        }

        return $this->call('POST', route('webhooks.stripe'), [], [], [], $server, $payload);
    }

    public function test_valid_signature_processes_event_and_credits_quota(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        Payment::factory()->for($user)->pending()->create([
            'stripe_checkout_session_id' => 'cs_test_valid_sig',
            'quantity' => 5,
        ]);

        $payload = $this->stripeCheckoutEventPayload('evt_valid', 'checkout.session.completed', [
            'id' => 'cs_test_valid_sig',
        ]);
        $signature = $this->validStripeSignatureHeader($payload, self::WEBHOOK_SECRET);

        $response = $this->postWebhook($payload, $signature);

        $response->assertNoContent(200);
        $this->assertSame(5, app(MeetingQuotaService::class)->remaining($user));
        $this->assertSame(PaymentStatus::Succeeded, Payment::sole()->status);
    }

    public function test_invalid_signature_is_rejected_with_400(): void
    {
        $payload = $this->stripeCheckoutEventPayload('evt_bad_sig', 'checkout.session.completed', [
            'id' => 'cs_test_whatever',
        ]);

        $response = $this->postWebhook($payload, 't=1700000000,v1=deadbeef0000000000000000000000000000000000000000000000000000');

        $response->assertStatus(400);
    }

    public function test_missing_signature_header_is_rejected_with_400(): void
    {
        $payload = $this->stripeCheckoutEventPayload('evt_no_sig', 'checkout.session.completed', [
            'id' => 'cs_test_whatever',
        ]);

        $response = $this->postWebhook($payload, null);

        $response->assertStatus(400);
    }

    public function test_signature_for_different_payload_is_rejected(): void
    {
        $originalPayload = $this->stripeCheckoutEventPayload('evt_tampered', 'checkout.session.completed', [
            'id' => 'cs_test_tampered',
        ]);
        $signature = $this->validStripeSignatureHeader($originalPayload, self::WEBHOOK_SECRET);

        // 署名は originalPayload に対するものだが、改ざんされた別内容を送る。
        $tamperedPayload = $this->stripeCheckoutEventPayload('evt_tampered', 'checkout.session.completed', [
            'id' => 'cs_test_completely_different',
        ]);

        $response = $this->postWebhook($tamperedPayload, $signature);

        $response->assertStatus(400);
    }

    public function test_duplicate_delivery_of_same_valid_event_does_not_double_credit(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $payment = Payment::factory()->for($user)->pending()->create([
            'stripe_checkout_session_id' => 'cs_test_http_dup',
            'quantity' => 3,
        ]);

        $payload = $this->stripeCheckoutEventPayload('evt_http_dup', 'checkout.session.completed', [
            'id' => 'cs_test_http_dup',
        ]);
        $signature = $this->validStripeSignatureHeader($payload, self::WEBHOOK_SECRET);

        $this->postWebhook($payload, $signature)->assertNoContent(200);
        $this->postWebhook($payload, $signature)->assertNoContent(200);

        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($user));
        $this->assertSame(
            1,
            MeetingQuotaTransaction::query()->where('related_payment_id', $payment->id)->count(),
        );
    }

    public function test_unrecognized_event_type_returns_no_content_without_error(): void
    {
        $payload = $this->stripeCheckoutEventPayload('evt_unrelated', 'payment_intent.created', [
            'id' => 'cs_test_unrelated',
        ]);
        $signature = $this->validStripeSignatureHeader($payload, self::WEBHOOK_SECRET);

        $this->postWebhook($payload, $signature)->assertNoContent(200);
    }

    public function test_malformed_json_body_is_rejected_with_400(): void
    {
        $payload = '{not valid json';
        $signature = $this->validStripeSignatureHeader($payload, self::WEBHOOK_SECRET);

        $response = $this->postWebhook($payload, $signature);

        $response->assertStatus(400);
    }

    public function test_missing_webhook_secret_returns_503_without_crashing(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $payload = $this->stripeCheckoutEventPayload('evt_no_secret', 'checkout.session.completed', [
            'id' => 'cs_test_no_secret',
        ]);

        $response = $this->postWebhook($payload, 't=1700000000,v1=whatever');

        $response->assertStatus(503);
    }

    public function test_route_requires_no_authentication(): void
    {
        // 未ログイン状態でも到達できる(認可はここでは行わない、正当性は署名検証のみで担保)。
        $payload = $this->stripeCheckoutEventPayload('evt_guest', 'checkout.session.completed', [
            'id' => 'cs_test_guest',
        ]);
        $signature = $this->validStripeSignatureHeader($payload, self::WEBHOOK_SECRET);

        $response = $this->postWebhook($payload, $signature);

        $response->assertStatus(200);
    }
}
