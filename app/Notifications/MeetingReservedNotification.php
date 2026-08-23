<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Meeting;

/**
 * 面談予約成立通知。当事者(受講生 / 担当コーチ)双方へ配信する。
 */
final class MeetingReservedNotification extends BusinessEventNotification
{
    public function __construct(private readonly Meeting $meeting) {}

    public function type(): NotificationType
    {
        return NotificationType::MeetingReserved;
    }

    public function title(): string
    {
        return '面談が予約されました';
    }

    public function message(): string
    {
        return $this->meeting->scheduled_at->format('Y/m/d H:i').' に面談が予約されました。';
    }

    public function body(): string
    {
        return $this->meeting->scheduled_at->format('Y年m月d日 H:i').' から面談が予約されました。詳細は面談ページをご確認ください。';
    }

    public function url(): string
    {
        return route('meetings.show', $this->meeting);
    }
}
