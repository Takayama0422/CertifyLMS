<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知 JSON API(S-A-05)のレスポンス整形。
 *
 * `data` に格納するキーは `BusinessEventNotification::toArray()` が書き込む
 * `notification_type` / `title` / `message` / `url` に統一されている(既存の
 * 通知一覧画面 `notifications/_partials/notification-row.blade.php` と同じ読み取り方)。
 * 本文プレビューは要件シート S4 の「`message`(または `body_preview`)」どおり、
 * 支給 Blade と同じ順序で `message` → `body_preview` → 空文字と切り替える
 * (どちらか一方しか持たない通知でも、フルページとポップオーバーの表示が食い違わないようにする)。
 * 未読判定は `read_at === null`(既存 Blade と同じ基準)。
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'type' => $data['notification_type'] ?? null,
            'title' => $data['title'] ?? '通知',
            'message' => $data['message'] ?? $data['body_preview'] ?? '',
            'url' => $data['url'] ?? route('notifications.index'),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
