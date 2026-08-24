<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 追加面談購入(Payment)の決済ステータス。
 *
 * pending: Checkout Session 作成直後(決済サービス側の結果通知待ち)。
 * succeeded: 決済サービスからの完了通知を受け、残面談回数への加算まで完了した状態。
 * failed: 決済サービスからの通知でキャンセル / 期限切れが確定した状態(残数は加算しない)。
 * refunded: 管理者が決済サービスのダッシュボードから手動返金した状態(本チケットでは表示のみ、遷移操作は対象外)。
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '保留',
            self::Succeeded => '完了',
            self::Failed => '失敗',
            self::Refunded => '返金済',
        };
    }
}
