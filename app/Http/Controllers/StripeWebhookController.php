<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\UseCases\Payment\HandleStripeWebhookAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe からの決済結果通知を受け取る公開窓口(認証なし、署名検証のみで正当性を担保)。
 *
 * 署名検証(`Stripe\Webhook::constructEvent`)はネットワーク通信を伴わないローカルな HMAC 検証のため、
 * PaymentGatewayContract 経由のモック対象にはしない(テストは実アルゴリズムで正規 / 不正な署名を作って検証する)。
 * 通知の業務処理自体は HandleStripeWebhookAction(冪等性・想定外イベントへの耐性を担保)へ委譲する。
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, HandleStripeWebhookAction $action): Response
    {
        $secret = config('services.stripe.webhook_secret');

        if (blank($secret)) {
            // 鍵未設定環境では署名の正当性を検証しようがないため、常に処理を拒否する(何もしない)。
            // 500 エラーとして落ちないことが「壊れない」要件上重要。
            return response()->noContent(503);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret,
            );
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return response()->noContent(400);
        }

        $action($event);

        return response()->noContent(200);
    }
}
