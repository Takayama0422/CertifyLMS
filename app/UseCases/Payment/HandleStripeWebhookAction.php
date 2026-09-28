<?php

declare(strict_types=1);

namespace App\UseCases\Payment;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\UseCases\MeetingQuota\PurchaseQuotaAction;
use Illuminate\Support\Facades\DB;
use Stripe\Event;
use Stripe\StripeObject;

/**
 * 署名検証済みの Stripe Event を処理するユースケース(決済サービスからの通知の本体処理)。
 *
 * 冪等性: `stripe_checkout_session_id` で Payment 行を行ロックしたうえで、
 * 現在の status が pending の場合のみ状態を進める。すでに succeeded / failed へ遷移済みなら
 * 何もせず正常終了する(同一通知の重複配信・リトライで残数が二重加算されない)。
 *
 * 想定外の通知への耐性:
 *   - 未対応の event.type は無視する(将来 Stripe が新種イベントを追加しても落ちない)。
 *   - event.data.object から Session が取れない、または対応する Payment が見つからない場合も無視する
 *     (存在しない Session ID を騙った通知、テスト用イベント等)。
 * いずれも例外を投げず 何もしないで正常終了することで、Webhook エンドポイントは常に 200 を返せる。
 */
final class HandleStripeWebhookAction
{
    public function __construct(
        private readonly PurchaseQuotaAction $purchaseQuotaAction,
    ) {}

    public function __invoke(Event $event): void
    {
        match ($event->type ?? null) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event),
            'checkout.session.expired' => $this->handleCheckoutExpired($event),
            default => null,
        };
    }

    private function handleCheckoutCompleted(Event $event): void
    {
        $session = $this->extractSession($event);

        if ($session === null) {
            return;
        }

        // 分割払い等の非同期決済手段では completed 到達時点でまだ未確定(unpaid)のことがある。
        // 都度購入(カード決済)のみが対象のため、paid 以外は「まだ完了していない」として無視する。
        if (($session->payment_status ?? null) !== 'paid') {
            return;
        }

        $sessionId = $session->id ?? null;

        if (! is_string($sessionId) || $sessionId === '') {
            return;
        }

        DB::transaction(function () use ($session, $sessionId) {
            $payment = Payment::query()
                ->where('stripe_checkout_session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if ($payment === null || $payment->status !== PaymentStatus::Pending) {
                // 対応する Payment が無い(想定外の通知)、またはすでに処理済み(重複通知)。冪等に無視する。
                return;
            }

            $paymentIntentId = $session->payment_intent ?? null;

            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'paid_at' => now(),
                'stripe_payment_intent_id' => is_string($paymentIntentId) ? $paymentIntentId : null,
            ]);

            ($this->purchaseQuotaAction)($payment);
        });
    }

    private function handleCheckoutExpired(Event $event): void
    {
        $session = $this->extractSession($event);
        $sessionId = $session?->id ?? null;

        if (! is_string($sessionId) || $sessionId === '') {
            return;
        }

        DB::transaction(function () use ($sessionId) {
            $payment = Payment::query()
                ->where('stripe_checkout_session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if ($payment === null || $payment->status !== PaymentStatus::Pending) {
                return;
            }

            $payment->update(['status' => PaymentStatus::Failed]);
        });
    }

    private function extractSession(Event $event): ?StripeObject
    {
        $session = $event->data?->object;

        return $session instanceof StripeObject ? $session : null;
    }
}
