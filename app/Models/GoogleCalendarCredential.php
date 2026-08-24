<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GoogleCalendarCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * コーチ 1 人につき 1 件の Google カレンダー連携情報(OAuth トークン)を表す Model。
 *
 * `user_id` は UNIQUE 制約があり、1 コーチにつき常に高々 1 件しか存在しない
 * (`User::googleCredential()` の hasOne と対応)。プライマリカレンダー固定のため `calendar_id` は
 * 常に 'primary' を保存する。認証情報の暗号化保存は本チケットのスコープ外(README 参照)。
 *
 * 関連: User(coach)
 */
class GoogleCalendarCredential extends Model
{
    /** @use HasFactory<GoogleCalendarCredentialFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'calendar_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'connected_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
