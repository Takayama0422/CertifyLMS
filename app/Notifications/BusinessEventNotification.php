<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 業務イベント通知(アプリ内 + メール)の共通基底クラス。
 *
 * チャネルは常に database + mail の両方(要件シート S8: 全種別ともアプリ内 + メール)。
 * `ShouldQueue` は実装しない(メール配信のキュー非同期化は S-B-04 のスコープ外、同期送信のまま)。
 *
 * `data` に格納するキーは支給画面(`notifications/_partials/notification-row.blade.php` /
 * `notifications/show.blade.php`)が読んでいる `notification_type` / `title` / `message` / `body` / `url` で統一する。
 *
 * 継承先は `type()` / `title()` / `message()` / `body()` / `url()` のみを実装する。
 */
abstract class BusinessEventNotification extends Notification
{
    use Queueable;

    abstract public function type(): NotificationType;

    abstract public function title(): string;

    /** 一覧行に表示する短い本文。 */
    abstract public function message(): string;

    /** 通知詳細ページ用のやや長い本文。 */
    abstract public function body(): string;

    /** クリック時に遷移する URL。 */
    abstract public function url(): string;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_type' => $this->type()->value,
            'title' => $this->title(),
            'message' => $this->message(),
            'body' => $this->body(),
            'url' => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting($this->title())
            ->line($this->body())
            ->action('詳細を確認する', $this->url())
            ->salutation('Certify LMS 運営チーム');
    }
}
