<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exceptions\Payment\PaymentGatewayUnavailableException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Services\Contracts\PaymentGatewayContract;
use App\Services\Payment\CheckoutSession;
use Closure;

/**
 * PaymentGatewayContract のテスト用フェイク実装。実通信を一切発生させない(T-A-04)。
 *
 * `$this->app->instance(PaymentGatewayContract::class, $fake)` で Container のバインディングを差し替えて使う。
 * 呼び出された引数を記録し、テスト側で「渡した Payment / MeetingPack が正しいか」をアサートできるようにする。
 */
final class FakePaymentGateway implements PaymentGatewayContract
{
    /** @var array<int, array{plan: MeetingPack, payment: Payment, successUrl: string, cancelUrl: string}> */
    public array $calls = [];

    private bool $shouldFail = false;

    /** @var Closure(Payment): void|null */
    private ?Closure $onCall = null;

    public function failNext(): void
    {
        $this->shouldFail = true;
    }

    /**
     * 決済サービス呼び出しの「最中」に走らせる観測用フック。
     * 呼び出し時点の DB トランザクション状態などを記録するために使う。
     *
     * @param Closure(Payment): void $callback
     */
    public function onCall(Closure $callback): void
    {
        $this->onCall = $callback;
    }

    public function createCheckoutSession(
        MeetingPack $plan,
        Payment $payment,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSession {
        $this->calls[] = compact('plan', 'payment', 'successUrl', 'cancelUrl');

        if ($this->onCall !== null) {
            ($this->onCall)($payment);
        }

        if ($this->shouldFail) {
            throw new PaymentGatewayUnavailableException;
        }

        return new CheckoutSession(
            id: 'cs_test_fake_'.$payment->id,
            url: 'https://checkout.stripe.test/session/cs_test_fake_'.$payment->id,
        );
    }
}
