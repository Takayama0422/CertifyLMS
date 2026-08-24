<?php

declare(strict_types=1);

namespace App\UseCases\Payment;

use App\Enums\PaymentStatus;
use App\Exceptions\Payment\PaymentGatewayUnavailableException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\Contracts\PaymentGatewayContract;
use Illuminate\Support\Facades\DB;

/**
 * 追加面談パックの購入開始(Checkout Session 作成)ユースケース。
 *
 * 1. 購入時点の価格 / 回数を Payment(status=pending)としてスナップショット保存
 * 2. 決済サービス側に Checkout Session を作成し、その ID を Payment に控える
 * を 1 DB トランザクションで行う。決済サービス側の呼び出しに失敗した場合は Payment 行ごとロールバックし、
 * 未完了の pending 行が残留しないようにする。
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
        return DB::transaction(function () use ($user, $plan, $successUrl, $cancelUrl) {
            $payment = Payment::create([
                'meeting_pack_id' => $plan->id,
                'user_id' => $user->id,
                'status' => PaymentStatus::Pending,
                'amount' => $plan->price,
                'quantity' => $plan->meeting_count,
            ]);

            $session = $this->gateway->createCheckoutSession($plan, $payment, $successUrl, $cancelUrl);

            $payment->update(['stripe_checkout_session_id' => $session->id]);

            return $session->url;
        });
    }
}
