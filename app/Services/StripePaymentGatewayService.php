<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Payment\PaymentGatewayUnavailableException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Services\Contracts\PaymentGatewayContract;
use App\Services\Payment\CheckoutSession;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * PaymentGatewayContract の正規実装。`stripe/stripe-php` を用いて実際に Stripe API を呼び出す。
 *
 * 価格は MeetingPack マスタの現在値からではなく、呼び出し側が渡す Payment(購入時点のスナップショット)の
 * amount / quantity から動的に price_data を組み立てる(`stripe_price_id` が未設定の SKU でも動作する)。
 * 通貨は円固定(jpy、最小通貨単位が存在しないため unit_amount にそのまま渡す)。
 */
final class StripePaymentGatewayService implements PaymentGatewayContract
{
    public function createCheckoutSession(
        MeetingPack $plan,
        Payment $payment,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSession {
        $secret = config('services.stripe.secret');

        if (blank($secret)) {
            throw new PaymentGatewayUnavailableException;
        }

        $client = new StripeClient($secret);

        try {
            $session = $client->checkout->sessions->create([
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $payment->id,
                'metadata' => [
                    'payment_id' => $payment->id,
                ],
                'line_items' => [
                    [
                        'quantity' => 1,
                        'price_data' => [
                            'currency' => 'jpy',
                            'unit_amount' => $payment->amount,
                            'product_data' => [
                                'name' => $plan->name,
                            ],
                        ],
                    ],
                ],
            ]);
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayUnavailableException($e);
        }

        if (! is_string($session->id) || ! is_string($session->url)) {
            throw new PaymentGatewayUnavailableException;
        }

        return new CheckoutSession(id: $session->id, url: $session->url);
    }
}
