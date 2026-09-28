<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\MeetingPack;
use App\Models\Payment;
use App\Services\Contracts\PaymentGatewayContract;
use App\Services\Payment\CheckoutSession;
use LogicException;

/**
 * テストの既定で `PaymentGatewayContract` に束縛する「呼ばれたら違反」の実装(T-A-04)。
 * 実装(`StripePaymentGatewayService`)は Stripe SDK で実通信するため、`FakePaymentGateway` へ
 * 差し替えずにチェックアウトを開始したら、違反として記録して止める。
 */
final class StrayPaymentGateway implements PaymentGatewayContract
{
    public function createCheckoutSession(
        MeetingPack $plan,
        Payment $payment,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSession {
        ExternalRequestGuard::record('stripe', 'createCheckoutSession');

        throw new LogicException('未モックの決済ゲートウェイ呼び出し。テストで FakePaymentGateway へ差し替えること。');
    }
}
