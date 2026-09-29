<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 通知種別。`notifications.data->notification_type` に格納する値と、支給画面のアイコン分岐
 * (`resources/views/notifications/_partials/notification-row.blade.php` ほか)が前提にしている
 * キー文字列を一致させるための Enum。`CompletionApproved` は S-B-04 のスコープ外(要件シート S12-02)。
 */
enum NotificationType: string
{
    case ChatMessageReceived = 'chat_message_received';
    case QaReplyReceived = 'qa_reply_received';
    case MeetingReserved = 'meeting_reserved';
    case MeetingCanceled = 'meeting_canceled';
    case AdminAnnouncement = 'admin_announcement';
    case MeetingReminder = 'meeting_reminder';
}
