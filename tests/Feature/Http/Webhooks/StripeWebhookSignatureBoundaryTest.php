<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Webhooks;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\StripeWebhookTestHelpers;
use Tests\TestCase;

/**
 * T-A-04: Stripe Webhook の署名検証の境界の検証(既存の `StripeWebhookTest` の正規 / 不正 / 欠落 / 二重配信を補完する)。
 *
 * モック手法: 署名検証はネットワーク通信を伴わないローカルな HMAC 検証のため、通信をモックするのではなく、
 * 署名生成ヘルパー(`StripeWebhookTestHelpers`)で Stripe の署名アルゴリズムどおりのヘッダを作って検証する。
 * 拒否した場合に、決済の状態も面談の残数も変わらないことまで確認する。
 */
#[Group('external')]
#[Group('stripe')]
class StripeWebhookSignatureBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use StripeWebhookTestHelpers;

    private const SECRET = 'whsec_boundary_test_secret';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::SECRET]);
        $this->user = User::factory()->student()->create(['max_meetings' => 0]);
        Payment::factory()->for($this->user)->pending()->create([
            'stripe_checkout_session_id' => 'cs_test_boundary',
            'quantity' => 3,
        ]);
    }

    private function payload(): string
    {
        return $this->stripeCheckoutEventPayload('evt_boundary', 'checkout.session.completed', ['id' => 'cs_test_boundary']);
    }

    private function postWebhook(string $payload, string $signatureHeader): TestResponse
    {
        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
        ], $payload);
    }

    private function assertNothingChanged(): void
    {
        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status, '拒否した通知で決済の状態を変えない');
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($this->user), '拒否した通知で残数を加算しない');
    }

    public function test_signature_made_with_a_different_secret_is_rejected(): void
    {
        $payload = $this->payload();

        $this->postWebhook($payload, $this->validStripeSignatureHeader($payload, 'whsec_attacker_secret'))->assertStatus(400);

        $this->assertNothingChanged();
    }

    public function test_signature_older_than_the_tolerance_is_rejected_to_prevent_replay(): void
    {
        $payload = $this->payload();
        $stale = $this->validStripeSignatureHeader($payload, self::SECRET, time() - 400);

        $this->postWebhook($payload, $stale)->assertStatus(400);

        $this->assertNothingChanged();
    }

    public function test_signature_within_the_tolerance_is_accepted(): void
    {
        $payload = $this->payload();
        $recent = $this->validStripeSignatureHeader($payload, self::SECRET, time() - 250);

        $this->postWebhook($payload, $recent)->assertStatus(200);

        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($this->user));
    }

    public function test_signature_timestamp_far_in_the_future_is_rejected(): void
    {
        $payload = $this->payload();
        $future = $this->validStripeSignatureHeader($payload, self::SECRET, time() + 400);

        $this->postWebhook($payload, $future)->assertStatus(400);

        $this->assertNothingChanged();
    }

    public function test_header_with_a_rotated_secret_signature_and_a_valid_one_is_accepted(): void
    {
        // シークレットのローテーション中は、ヘッダに v1 が複数含まれる。いずれかが有効なら正規の通知
        $payload = $this->payload();
        $valid = $this->validStripeSignatureHeader($payload, self::SECRET);
        $withExtraInvalid = $valid.',v1='.str_repeat('a', 64);

        $this->postWebhook($payload, $withExtraInvalid)->assertStatus(200);

        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($this->user));
    }

    public function test_header_whose_v1_signatures_are_all_invalid_is_rejected(): void
    {
        $payload = $this->payload();

        $this->postWebhook($payload, 't='.time().',v1='.str_repeat('a', 64).',v1='.str_repeat('b', 64))->assertStatus(400);

        $this->assertNothingChanged();
    }

    public function test_header_without_a_timestamp_is_rejected(): void
    {
        $payload = $this->payload();
        $signature = hash_hmac('sha256', time().'.'.$payload, self::SECRET);

        $this->postWebhook($payload, "v1={$signature}")->assertStatus(400);

        $this->assertNothingChanged();
    }

    public function test_header_with_only_a_test_scheme_signature_is_rejected(): void
    {
        // v0 は Stripe のテスト用の旧スキーム。本番の検証に用いる v1 が無ければ拒否する
        $payload = $this->payload();
        $timestamp = time();
        $v0 = hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET);

        $this->postWebhook($payload, "t={$timestamp},v0={$v0}")->assertStatus(400);

        $this->assertNothingChanged();
    }

    public function test_empty_signature_header_is_rejected(): void
    {
        $this->postWebhook($this->payload(), '')->assertStatus(400);

        $this->assertNothingChanged();
    }
}
