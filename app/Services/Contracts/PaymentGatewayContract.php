<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Exceptions\Payment\PaymentGatewayUnavailableException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Services\Payment\CheckoutSession;

/**
 * 追加面談購入(S-A-03)が外部決済サービスとやり取りする窓口の契約。
 *
 * 正規実装(StripePaymentGatewayService)は `stripe/stripe-php` を用いて実際に Stripe API を呼び出す。
 * テストでは本契約に対するフェイク実装を Container へ差し替え、実通信を発生させずに
 * `CreateCheckoutSessionAction` 等の呼び出し側ロジックを検証する(T-A-04「外部連携は実通信を発生させない」)。
 *
 * 署名検証(Webhook受信側)は本契約の対象外。ネットワーク通信を伴わないローカルな検証処理のため、
 * `\Stripe\Webhook::constructEvent()` を直接用いる(モック不要、実アルゴリズムでテスト可能)。
 */
interface PaymentGatewayContract
{
    /**
     * 都度購入用の Checkout Session を作成する。
     *
     * @param MeetingPack $plan 購入対象パック(価格 / 回数はこの時点の値を Session 生成に使う)
     * @param Payment $payment 事前に pending で作成済みの Payment 行(metadata で紐付ける)
     * @param string $successUrl 決済完了後に戻る URL
     * @param string $cancelUrl 中断時に戻る URL
     *
     * @throws PaymentGatewayUnavailableException 鍵未設定 / 決済サービス側の通信エラー時
     */
    public function createCheckoutSession(
        MeetingPack $plan,
        Payment $payment,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSession;
}
