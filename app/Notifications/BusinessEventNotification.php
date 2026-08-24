<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 業務イベント通知(アプリ内 + メール)の共通基底クラス。
 *
 * チャネルは常に database + mail の両方(要件シート S8: 全種別ともアプリ内 + メール)。
 *
 * **T-A-05 でキュー非同期化**: `ShouldQueueAfterCommit` を実装し、`database` / `mail` 両チャネルへの
 * 配信を 1 つのバックグラウンドジョブ(`SendQueuedNotifications`)にまとめてキューへ逃がす
 * (S-B-04 時点では「メール配信のキュー非同期化はスコープ外」としていたが、本チケットで対象に含める)。
 * `ShouldQueueAfterCommit` により、発火元が `DB::transaction()` 内から `notify()` を呼んでいても、
 * 実際にキューへ積まれるのはトランザクション commit 後になる(ロールバック時に配信が漏れない)。
 * トランザクションの外から呼ばれた場合(既に `DB::afterCommit()` 内 / トランザクション外の同期処理)は
 * 即座にキューへ積まれるため、既存の `DB::afterCommit()` によるラップと二重に安全側へ倒れるだけで、
 * 配信タイミング(誰に何が届くか)自体は変えない。
 *
 * 一時的な送信失敗(SMTP 接続エラー等)は `$tries` / `backoff()` の段階的リトライで吸収し、
 * 上限を超えたものは `failed_jobs` に記録される(`php artisan queue:retry` で再投入可能)。
 *
 * `data` に格納するキーは支給画面(`notifications/_partials/notification-row.blade.php` /
 * `notifications/show.blade.php`)が読んでいる `notification_type` / `title` / `message` / `body` / `url` で統一する。
 *
 * 継承先は `type()` / `title()` / `message()` / `body()` / `url()` のみを実装する。
 */
abstract class BusinessEventNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** 最大試行回数(初回 + 4 回のリトライ)。超過分は failed_jobs へ記録される。 */
    public int $tries = 5;

    /**
     * リトライ間隔(秒)。1 回ごとに段階的に待機時間を伸ばす。
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

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
