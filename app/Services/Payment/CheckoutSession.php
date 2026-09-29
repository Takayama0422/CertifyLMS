<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * 決済サービス側で作成された Checkout Session の受講生に必要な最小情報。
 *
 * `PaymentGatewayContract` の実装(Stripe SDK 等)の戻り値をこの単純な値オブジェクトへ変換することで、
 * 呼び出し側(UseCase / Controller)が Stripe SDK の型に直接依存しないようにする。
 */
final class CheckoutSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
    ) {}
}
