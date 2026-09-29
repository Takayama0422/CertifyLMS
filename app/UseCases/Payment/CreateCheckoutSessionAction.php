<?php

declare(strict_types=1);

namespace App\UseCases\Payment;

use App\Enums\PaymentStatus;
use App\Exceptions\Payment\PaymentGatewayUnavailableException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\Contracts\PaymentGatewayContract;
use Throwable;

/**
 * 追加面談パックの購入開始(Checkout Session 作成)ユースケース。
 *
 * 1. 購入時点の価格 / 回数を Payment(status=pending)としてスナップショット保存し、先に確定させる
 * 2. 決済サービス側に Checkout Session を作成する
 * 3. 返ってきた Session ID を Payment へ書き戻す
 *
 * 外部サービスへの通信は DB トランザクションの外で行う。Session 作成後にトランザクションのコミットが
 * 失敗すると「決済サービス側の Session だけが生き残り、対応する Payment 行が消える」状態になり、
 * その Session で決済されても Webhook 側が対応する Payment を見つけられず残数が加算されないため
 * (課金されたのに残数が増えない)。先に Payment を確定させることでこの窓を塞ぐ。
 * 併せて、外部 API の往復中に DB トランザクションを開いたままにしない。
 *
 * Session を作れなかった場合、その Payment は決して完了しないため、確定済みの行を打ち消して
 * 未完了の pending 行が残留しないようにする(従来のロールバックと同じ結果)。
 *
 * 認可(受講生本人が学習中か / パックが公開中か)は呼出元の Controller が Policy で判定済みの前提。
 */
final class CreateCheckoutSessionAction
{
    public function __construct(
        private readonly PaymentGatewayContract $gateway,
    ) {}

    /**
     * @throws PaymentGatewayUnavailableException
     */
    public function __invoke(
        User $user,
        MeetingPack $plan,
        string $successUrl,
        string $cancelUrl,
    ): string {
        $payment = Payment::create([
            'meeting_pack_id' => $plan->id,
            'user_id' => $user->id,
            'status' => PaymentStatus::Pending,
            'amount' => $plan->price,
            'quantity' => $plan->meeting_count,
        ]);

        try {
            $session = $this->gateway->createCheckoutSession($plan, $payment, $successUrl, $cancelUrl);
        } catch (Throwable $e) {
            $payment->delete();

            throw $e;
        }

        $payment->update(['stripe_checkout_session_id' => $session->id]);

        return $session->url;
    }
}
