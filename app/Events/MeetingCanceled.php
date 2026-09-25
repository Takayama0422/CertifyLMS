<?php

declare(strict_types=1);

namespace App\Events;

use App\Listeners\SendMeetingPartyNotifications;
use App\Models\Meeting;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 面談がキャンセルされた際に発火するイベント。
 *
 * `SendMeetingPartyNotifications` リスナーが、当事者のうち操作を行っていない側
 * (`canceled_by_user_id` の相手側)へ通知を配信する。操作者本人へは配信しない。
 * `canceled_by_user_id` が未設定の場合(Seeder・バッチ等、当事者以外が発火する経路)は
 * 担当コーチが配信先になる。
 * MeetingController::cancel() 以外(Seeder・バッチ・将来の別経路)からキャンセルする場合も、
 * 本イベントを発火しさえすれば同じ通知配信規則に乗る。
 *
 * @see SendMeetingPartyNotifications
 */
final class MeetingCanceled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Meeting $meeting) {}
}
