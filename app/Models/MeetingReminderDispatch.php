<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeetingReminderWindow;
use Database\Factories\MeetingReminderDispatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 面談リマインダーの配信予約 / 配信済み記録 1 件(面談 × 配信窓 × 受信者)。
 * UNIQUE(meeting_id, window, user_id) が二重配信防止の実体(`SendMeetingRemindersAction` 参照)。
 * `sent_at` が null の行は「予約したが送信未確定」を表す(送信成功で埋まる。送信失敗時は
 * 行ごと削除して再送可能にするが、プロセスが落ちた場合は null のまま残ることがあり、
 * 一定時間後に再利用される)。
 */
class MeetingReminderDispatch extends Model
{
    /** @use HasFactory<MeetingReminderDispatchFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'meeting_id',
        'window',
        'user_id',
        'sent_at',
    ];

    protected $casts = [
        'window' => MeetingReminderWindow::class,
        'sent_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Meeting, $this>
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
