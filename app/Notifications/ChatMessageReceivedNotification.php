<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\ChatMessage;
use Illuminate\Support\Str;

/**
 * chat メッセージ受信通知。送信者以外のルーム参加者(相手方)へ配信する。
 */
final class ChatMessageReceivedNotification extends BusinessEventNotification
{
    public function __construct(private readonly ChatMessage $message) {}

    public function type(): NotificationType
    {
        return NotificationType::ChatMessageReceived;
    }

    public function title(): string
    {
        return $this->message->sender->name.'さんからメッセージが届きました';
    }

    public function message(): string
    {
        return Str::limit($this->message->body, 60);
    }

    public function body(): string
    {
        return $this->message->body;
    }

    public function url(): string
    {
        return route('chat.show', $this->message->chat_room_id);
    }
}
