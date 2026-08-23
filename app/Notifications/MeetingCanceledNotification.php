<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Meeting;

/**
 * 面談キャンセル通知。当事者(受講生 / 担当コーチ)双方へ配信する。
 */
final class MeetingCanceledNotification extends BusinessEventNotification
{
    public function __construct(private readonly Meeting $meeting) {}

    public function type(): NotificationType
    {
        return NotificationType::MeetingCanceled;
    }

    public function title(): string
    {
        return '面談がキャンセルされました';
    }

    public function message(): string
    {
        return $this->meeting->scheduled_at->format('Y/m/d H:i').' の面談がキャンセルされました。';
    }

    public function body(): string
    {
        return $this->meeting->scheduled_at->format('Y年m月d日 H:i').' からの面談がキャンセルされました。詳細は面談ページをご確認ください。';
    }

    public function url(): string
    {
        return route('meetings.show', $this->meeting);
    }
}
