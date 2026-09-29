<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Announcement;
use Illuminate\Support\Str;

/**
 * 管理者お知らせ配信通知。対象受講生へ配信する。
 *
 * 他 3 種(chat / qa / meeting)と異なり遷移先の業務画面を持たないため、`url()` は
 * 通知詳細ページ(`GET /notifications/{notification}`、本チケットで新設)を指す。
 * `Illuminate\Notifications\Notification::$id` はコンストラクタで採番済みのため、
 * `toArray()` の時点で自身の DatabaseNotification ID を参照できる。
 */
final class AdminAnnouncementNotification extends BusinessEventNotification
{
    public function __construct(private readonly Announcement $announcement) {}

    public function type(): NotificationType
    {
        return NotificationType::AdminAnnouncement;
    }

    public function title(): string
    {
        return $this->announcement->title;
    }

    public function message(): string
    {
        return Str::limit($this->announcement->body, 60);
    }

    public function body(): string
    {
        return $this->announcement->body;
    }

    public function url(): string
    {
        return route('notifications.show', $this->id);
    }
}
