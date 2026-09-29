<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 追加面談パックの購入(決済)記録。1 購入 = 1 レコード。
 *
 * `amount` / `quantity` は購入時点の MeetingPack の price / meeting_count のスナップショットであり、
 * 後から MeetingPack 側のマスタ値が変わっても本レコードの値は変化しない(監査要件)。
 *
 * ライフサイクル: pending(Checkout Session 作成直後) → succeeded(Webhook の決済完了通知で確定、
 * 同時に MeetingQuotaTransaction(type=purchased) を 1 件生成) / failed(キャンセル・期限切れ)。
 * pending のまま残る行は「決済未完了」を意味し、残数には一切影響しない。
 *
 * 関連: MeetingPack(購入対象、パック削除時は null化) / User(購入者) / MeetingQuotaTransaction(残数加算の監査ログ、
 * `related_payment_id` で本モデルを belongsTo する側から参照される)
 */
class Payment extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'meeting_pack_id',
        'user_id',
        'status',
        'amount',
        'quantity',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'paid_at',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'integer',
        'quantity' => 'integer',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<MeetingPack, $this>
     */
    public function meetingPack(): BelongsTo
    {
        return $this->belongsTo(MeetingPack::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
