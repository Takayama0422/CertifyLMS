<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\MeetingReminderWindow;
use App\Enums\NotificationType;
use App\Models\Meeting;

/**
 * 面談リマインダー通知。前日(eve)/ 開始 1 時間前(one_hour_before)の 2 タイミングで、
 * 予約済み面談の当事者(受講生 + 担当コーチ)へ配信する(要件シート S7/S8)。
 */
final class MeetingReminderNotification extends BusinessEventNotification
{
    public function __construct(
        private readonly Meeting $meeting,
        private readonly MeetingReminderWindow $window,
    ) {}

    public function type(): NotificationType
    {
        return NotificationType::MeetingReminder;
    }

    public function title(): string
    {
        return $this->window === MeetingReminderWindow::Eve
            ? '明日の面談のリマインダーです'
            : 'まもなく面談が始まります';
    }

    public function message(): string
    {
        return $this->meeting->scheduled_at->format('Y/m/d H:i').' から面談が予定されています。';
    }

    public function body(): string
    {
        $when = $this->window === MeetingReminderWindow::Eve ? '明日' : 'まもなく';

        return $when.'、'.$this->meeting->scheduled_at->format('Y年m月d日 H:i').' から面談が予定されています。詳細は面談ページをご確認ください。';
    }

    public function url(): string
    {
        return route('meetings.show', $this->meeting);
    }
}
