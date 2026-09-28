<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * 追加面談パック購入完了(決済サービスからの通知確定時)の面談回数加算ユースケース。
 *
 * `related_payment_id` に Payment の ID を持たせ、履歴画面から購入内容(パック名等)を辿れるようにする。
 * `amount` には Payment に保存済みの購入時点の quantity スナップショットをそのまま使う
 * (呼出元の Webhook 処理側で「同一 Payment に対して 1 度だけ呼ぶ」冪等性を担保している前提)。
 */
final class PurchaseQuotaAction
{
    public function __invoke(Payment $payment): MeetingQuotaTransaction
    {
        return DB::transaction(fn () => MeetingQuotaTransaction::create([
            'user_id' => $payment->user_id,
            'type' => MeetingQuotaTransactionType::Purchased,
            'amount' => $payment->quantity,
            'related_payment_id' => $payment->id,
            'occurred_at' => now(),
        ]));
    }
}
