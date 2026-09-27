<?php

declare(strict_types=1);

namespace App\Events;

use App\Listeners\SendMeetingPartyNotifications;
use App\Models\Meeting;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 面談予約が確定した際に発火するイベント。
 *
 * `SendMeetingPartyNotifications` リスナーが担当コーチへ通知を配信する(予約操作は常に受講生本人が
 * 行うため、操作者である受講生本人へは配信しない)。
 * MeetingController::store() 以外(Seeder・バッチ・将来の別経路)から面談を予約する場合も、
 * 本イベントを発火しさえすれば同じ通知配信規則に乗る。
 *
 * @see SendMeetingPartyNotifications
 */
final class MeetingReserved
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Meeting $meeting) {}
}
